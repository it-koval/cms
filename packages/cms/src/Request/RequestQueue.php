<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\EditLock;
use Itk\Cms\Entity\Request;
use Itk\Cms\Entity\RequestQueueEntry;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\SendMail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A committed intent survives a dead broker, a lost dispatch response and a process dying before publication.
 *
 * @internal
 */
final class RequestQueue
{
    private bool $publishing = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?AdminLocale $locale = null,
    ) {
    }

    /** Initial submission: both the reservation and its intent use the caller's transaction. */
    public function enqueue(int $id, string $channel, bool $resend = false): void
    {
        $entry = $this->em->wrapInTransaction(function () use ($id, $channel, $resend): ?string {
            $job = RequestDeliverer::reserve($this->em, $id, $channel, $resend);

            return null === $job ? null : self::stageJob($this->em, new SendRequestNotification($id, $resend, $channel, $job));
        });
        if (null !== $entry) {
            $this->publish($entry);
        }
    }

    /** Called INSIDE the reservation transaction, including an independent committed-request reservation. */
    public static function stageJob(EntityManagerInterface $manager, SendRequestNotification|SendMail $message, int $delaySeconds = 0): string
    {
        $id = $message instanceof SendRequestNotification ? $message->requestId : (int) $message->ref;
        $request = $manager->getReference(Request::class, $id);
        \assert($request instanceof Request);
        $entry = new RequestQueueEntry($request, $message);
        $entry->availableAt = $delaySeconds > 0 ? \time() + $delaySeconds : 0;
        $manager->persist($entry);

        return $entry->id;
    }

    public function enqueueMail(SendMail $mail): void
    {
        $entry = $this->em->wrapInTransaction(fn (): string => self::stageJob($this->em, $mail));
        $this->publish($entry);
    }

    /** An ambient transaction may still roll back: a worker or the recovery command publishes after commit. */
    public function recover(int $limit = 50, ?int $requestId = null): int
    {
        $db = $this->em->getConnection();
        if ($this->publishing || !$db->isAutoCommit() || $db->isTransactionActive()) {
            return 0;
        }
        try {
            if ($db instanceof PrimaryReadReplicaConnection) {
                $db->ensureConnectedToPrimary();
            }
            $where = null === $requestId ? '' : ' AND request_id = ?';
            $parameters = null === $requestId ? [\time()] : [\time(), $requestId];
            $ids = $db->fetchFirstColumn('SELECT id FROM itk_request_queue WHERE available_at <= ?' . $where . ' ORDER BY available_at, id LIMIT ' . \max(1, \min(500, $limit)), $parameters);
        } catch (\Throwable $failure) {
            $this->logger->error('Request notification recovery could not read committed intents.', ['exceptionType' => $failure::class]);

            return 0;
        }
        $count = 0;
        foreach ($ids as $id) {
            $count += (int) $this->publish((string) $id);
        }

        return $count;
    }

    /** Publish at least once; request job identity and mail identity make replay safe at the receiving side. */
    public function publish(string $id): bool
    {
        $db = $this->em->getConnection();
        if ($this->publishing || !$db->isAutoCommit() || $db->isTransactionActive()) {
            return false;
        }
        $this->publishing = true;
        try {
            if ($db instanceof PrimaryReadReplicaConnection) {
                $db->ensureConnectedToPrimary();
            }

            return EditLock::onConnection($db, 'request-queue', $id, function () use ($db, $id): bool {
                $row = $db->fetchAssociative('SELECT kind, payload, available_at FROM itk_request_queue WHERE id = ?', [$id]);
                if (false === $row || (int) $row['available_at'] > \time()) {
                    return false;
                }
                /** @var array<string, mixed> $payload */
                $payload = \json_decode((string) $row['payload'], true, 512, \JSON_THROW_ON_ERROR);
                $message = 'messenger' === $row['kind'] ? new SendRequestNotification(...$payload) : new SendMail(...$payload);
                if ($message instanceof SendRequestNotification) {
                    $channels = $db->fetchOne('SELECT channels FROM itk_request WHERE id = ?', [$message->requestId]);
                    $states = false === $channels ? [] : \json_decode((string) $channels, true, 512, \JSON_THROW_ON_ERROR);
                    $current = $states[$message->channel] ?? [];
                    if (($current['job'] ?? null) !== $message->jobId || \in_array($current['phase'] ?? null, ['done', 'failed', 'uncertain'], true)) {
                        $db->delete('itk_request_queue', ['id' => $id]);

                        return false;
                    }
                }
                // Persist backoff BEFORE dispatch. A crash cannot strand the reservation without a retryable intent.
                $db->executeStatement('UPDATE itk_request_queue SET available_at = ? WHERE id = ?', [\time() + 60, $id]);
                try {
                    $this->bus->dispatch($message);
                } catch (\Throwable $failure) {
                    // Dispatch can throw after the transport accepted the envelope. Keep the original job valid and retry its intent.
                    $this->logger->error('Request notification publication will be retried.', ['requestId' => $message instanceof SendRequestNotification ? $message->requestId : $message->ref, 'exceptionType' => $failure::class]);
                    if ($message instanceof SendRequestNotification) {
                        try {
                            if ($failure instanceof HandlerFailedException
                                && $failure->getEnvelope()->getMessage() === $message
                                && [] !== $failure->getWrappedExceptions(RequestNotDeliveredException::class, true)
                                && $this->settleSynchronousRefusal($message)) {
                                $db->delete('itk_request_queue', ['id' => $id]);
                            } else {
                                $this->publicationFailed($message);
                            }
                        } catch (\Throwable $recordFailure) {
                            $this->logger->error('Request publication failure could not be recorded; its intent remains recoverable.', ['exceptionType' => $recordFailure::class]);
                        }
                    }

                    return false;
                }
                $db->delete('itk_request_queue', ['id' => $id]);

                return true;
            });
        } catch (\Throwable $failure) {
            // The intent is committed already. A bookkeeping/lock failure must not turn a saved form into a failed submission.
            $this->logger->error('Request notification publication stopped; its intent remains recoverable.', ['exceptionType' => $failure::class]);

            return false;
        } finally {
            $this->publishing = false;
        }
    }

    private function publicationFailed(SendRequestNotification $message): void
    {
        $write = static fn (): string => I18n::t(Request::CHANNEL_MAX === $message->channel ? 'http.max_queue_failed' : 'http.telegram_queue_failed', ['reason' => I18n::t('http.request_queue_failed')]);
        $error = null !== $this->locale ? $this->locale->runDefault($write) : $write();
        RequestDeliverer::recordQueueFailure($this->em, $this->events ?? new \Symfony\Component\EventDispatcher\EventDispatcher(), $message->requestId, $message->channel, $error, $message->jobId, $this->logger, keepJob: true);
    }

    private function settleSynchronousRefusal(SendRequestNotification $message): bool
    {
        return (bool) RequestDeliverer::serialized($this->em, $message->requestId, $message->channel, static function (EntityManagerInterface $manager) use ($message): bool {
            return $manager->wrapInTransaction(static function () use ($manager, $message): bool {
                $request = $manager->createQuery('SELECT r FROM ' . Request::class . ' r WHERE r.id = :id')
                    ->setParameter('id', $message->requestId)->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
                    ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
                if (null === $request || $message->jobId !== $request->channelJob($message->channel)) {
                    return true;
                }
                $phase = $request->channelJobPhase($message->channel);
                if ('retry' === $phase) {
                    $request->markChannelJob($message->channel, (string) $message->jobId, 'failed');
                }

                return \in_array($phase, ['retry', 'done', 'failed', 'uncertain'], true);
            });
        });
    }
}

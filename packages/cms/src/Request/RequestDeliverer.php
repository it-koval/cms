<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\EditLock;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Delivers a saved request to one messenger and records the outcome in the request. The one place where the queue handler and «Отправить снова» do that.
 * Each delivery is a job of one channel: it is reserved before dispatch and claimed under a short row lock, so a stale or superseded job cannot send.
 * One session lock reserves the request and channel from the first read through the outcome event. The network call holds no row lock.
 * A short transaction refreshes the row and saves the outcome, including when the caller has its own transaction (then on a connection of its own):
 * Telegram and MAX are delivered by separate messages, possibly by two workers at once, and each one writes the delivery state of the whole request.
 * A messenger that already has the request is not sent it again by a queue retry (only «Отправить снова» does that).
 * The next worker on the same channel sees the committed outcome before deciding whether to send. Events are dispatched after that commit.
 * Accepted parts are checkpointed before the final outcome. A retry resumes at the first unconfirmed part in each chat.
 *
 * @internal
 */
final class RequestDeliverer
{
    private const LEASE_SECONDS = 3600;
    /** @var array<string, true> */
    private static array $announcing = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramNotifier $telegram,
        private readonly MaxNotifier $max,
        private readonly AdminLocale $locale,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Reserve before dispatch. An accepted/retrying job keeps its identity until it settles. */
    public static function reserve(EntityManagerInterface $em, int $id, string $channel, bool $resend): ?string
    {
        return self::reserveJob($em, $id, $channel, $resend);
    }

    /** @param (\Closure(EntityManagerInterface, string): void)|null $onReserved */
    public function reserveForDispatch(int $id, string $channel, bool $resend, ?\Closure $onReserved = null, bool $onlyIfFailed = false): ?string
    {
        return self::serialized($this->em, $id, $channel, static fn (EntityManagerInterface $manager): ?string => self::reserveJob($manager, $id, $channel, $resend, $onReserved, $onlyIfFailed));
    }

    /** @param (\Closure(EntityManagerInterface, string): void)|null $onReserved */
    private static function reserveJob(EntityManagerInterface $em, int $id, string $channel, bool $resend, ?\Closure $onReserved = null, bool $onlyIfFailed = false): ?string
    {
        return $em->wrapInTransaction(static function () use ($em, $id, $channel, $resend, $onReserved, $onlyIfFailed): ?string {
            $request = self::load($em, $id, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || \in_array($channel, $request->queuedChannels(), true) || (!$resend && Request::DELIVERY_SENT === $request->channelState($channel))) {
                return null;
            }
            if ($onlyIfFailed && Request::DELIVERY_FAILED !== $request->channelState($channel)) {
                return null;
            }
            if ('processing' === $request->channelJobPhase($channel)) {
                $key = EditLock::key('request-delivery', $id . ':' . $channel);
                // Retain the lock through commit. A live worker still owns its session lock even after the lease expires.
                if (!$em->getConnection()->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [$key])) {
                    return null;
                }
            }
            $continued = \in_array($request->channelJobPhase($channel), ['queued', 'processing', 'retry', 'failed'], true) && [] !== $request->channelParts($channel);
            $job = ($continued ? $request->channelJob($channel) : null) ?? \bin2hex(\random_bytes(16));
            $request->markChannelJob($channel, $job, 'queued', \time() + self::LEASE_SECONDS);
            $onReserved?->__invoke($em, $job);

            return $job;
        });
    }

    /**
     * @param Request::CHANNEL_* $channel
     * @param (\Closure(EntityManagerInterface, string): void)|null $onReserved
     */
    public function deliverSynchronously(int $id, string $channel, bool $resend, ?\Closure $onReserved = null, bool $onlyIfFailed = false): void
    {
        self::serialized($this->em, $id, $channel, function (EntityManagerInterface $manager, ?Connection $independent) use ($id, $channel, $resend, $onReserved, $onlyIfFailed): void {
            $job = self::reserveJob($manager, $id, $channel, $resend, $onReserved, $onlyIfFailed);
            if (null !== $job && false === $this->sendAndRecord($manager, $independent, $id, $channel, $resend, $job)) {
                $this->settle($manager, $id, $channel, $job, 'failed');
            }
        });
    }

    /**
     * @param Request::CHANNEL_* $channel
     *
     * @return bool|null true needs no retry, false retries this job, null means the request was deleted
     */
    public function deliver(int $requestId, string $channel, bool $resend, ?string $jobId = null): ?bool
    {
        return self::serialized($this->em, $requestId, $channel, fn (EntityManagerInterface $manager, ?Connection $independent): ?bool => $this->sendAndRecord($manager, $independent, $requestId, $channel, $resend, $jobId));
    }

    /** @param Request::CHANNEL_* $channel */
    private function sendAndRecord(EntityManagerInterface $manager, ?Connection $independent, int $requestId, string $channel, bool $resend, ?string $jobId): ?bool
    {
        $notifier = Request::CHANNEL_MAX === $channel ? $this->max : $this->telegram;
        $this->locale->runDefault(fn () => self::dispatchPending($manager, $independent, $requestId, $channel, $notifier->announce(...), $this->logger));
        // Direct callers represent a new action. Old queue jobs share a stable legacy identity.
        if (null === $jobId) {
            $jobId = self::reserve($manager, $requestId, $channel, $resend);
            if (null === $jobId) {
                $existing = self::load($manager, $requestId);
                if (null === $existing) {
                    self::checkNotUncommitted($this->em, $independent, $requestId);

                    return null;
                }
                if ('retry' !== $existing->channelJobPhase($channel)) {
                    return true;
                }
                $jobId = (string) $existing->channelJob($channel);
            }
        }
        $busyUntil = 0;
        // The row lock also verifies that an ambient transaction is not holding the request before irreversible network I/O.
        $request = $manager->wrapInTransaction(static function () use ($manager, $requestId, $channel, $resend, $jobId, &$busyUntil): ?Request {
            $request = self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request) {
                return null;
            }
            if ('legacy' === $jobId && null === $request->channelJob($channel)) {
                if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel)) {
                    return null;
                }
                $request->markChannelJob($channel, $jobId, 'queued', \time() + self::LEASE_SECONDS);
            }
            if ($jobId !== $request->channelJob($channel) || \in_array($request->channelJobPhase($channel), ['done', 'failed', 'uncertain'], true)) {
                return null;
            }
            if ('processing' === $request->channelJobPhase($channel) && [] === $request->channelParts($channel) && $request->channelJobLease($channel) > \time()) {
                $busyUntil = $request->channelJobLease($channel);

                return null;
            }
            $request->markChannelJob($channel, $jobId, 'processing', \time() + self::LEASE_SECONDS);
            $request->markChannelResend($channel, $request->channelResend($channel) ?? $resend);

            return $request;
        });
        if ($busyUntil > 0) {
            // Throw after commit: wrapInTransaction closes the entity manager on an exception.
            throw new RecoverableMessageHandlingException('Request delivery is already running.', retryDelay: ($busyUntil - \time() + 1) * 1000);
        }
        if (null === $request) {
            if (null === self::load($manager, $requestId)) {
                self::checkNotUncommitted($this->em, $independent, $requestId);

                return null;
            }

            return true;
        }
        $resend = $request->channelResend($channel) ?? $resend;
        $progress = new DeliveryProgress($request->channelParts($channel), static function (string $key, ?array $part) use ($manager, $requestId, $channel, $jobId): void {
            $manager->wrapInTransaction(static function () use ($manager, $requestId, $channel, $jobId, $key, $part): void {
                $current = self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE);
                if (null !== $current) {
                    $current->markChannelJob($channel, $jobId, 'processing', \time() + self::LEASE_SECONDS);
                    $current->markChannelPart($channel, $key, $part);
                }
            });
        });
        try {
            // a chat is read by many, in admin_locale: also when a sync transport runs this inside the request of one person
            $outcome = $this->locale->runDefault(static fn (): ChannelOutcome => $notifier->attempt($request, $resend, $progress));
        } catch (\Throwable $e) {
            try {
                $this->settle($manager, $requestId, $channel, $jobId, 'retry');
            } catch (\Throwable) {
                // Preserve the delivery exception if state persistence also failed; the lease is bounded.
            }
            throw $e;
        }
        $recorded = $manager->wrapInTransaction(static function () use ($manager, $requestId, $channel, $jobId, $outcome, $resend): ?Request {
            $request = self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || (null !== $request->channelJob($channel) && $jobId !== $request->channelJob($channel))) {
                return null;
            }
            // A pre-job worker can replace the JSON while recording the other messenger.
            // Restore missing metadata, but never overwrite another job's identity.
            if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel) && Request::DELIVERY_SENT !== $outcome->state) {
                $request->markChannelJob($channel, $jobId, 'done');

                return null;
            }
            $wasFailed = Request::DELIVERY_FAILED === $request->channelState($channel);
            $request->markChannel($channel, $outcome->state, $outcome->error);
            $phase = $outcome->uncertain ? 'uncertain' : ($outcome->isFailed() ? 'retry' : 'done');
            $request->markChannelJob($channel, $jobId, $phase, 'retry' === $phase ? \time() + self::LEASE_SECONDS : 0);
            if (!$outcome->isPending() || $wasFailed) {
                $request->enqueueChannelAnnouncement($channel, ['delivered' => Request::DELIVERY_SENT === $outcome->state, 'cancelled' => $outcome->isPending(), 'error' => $outcome->error]);
            }

            return $request;
        });
        if (null === $recorded) {
            return null === self::load($manager, $requestId) ? null : true;
        }
        $this->locale->runDefault(fn () => self::dispatchPending($manager, $independent, $requestId, $channel, $notifier->announce(...), $this->logger));

        return !$outcome->isFailed() || $outcome->uncertain;
    }

    public function partCount(Request $request, string $channel, bool $resend): int
    {
        $notifier = Request::CHANNEL_MAX === $channel ? $this->max : $this->telegram;

        return $this->locale->runDefault(static fn (): int => \count($notifier->parts($request, $resend)->texts));
    }

    public function queueFailed(int $id, string $channel, string $job, string $error): void
    {
        $this->settlePhase($id, $channel, $job, 'failed', $error);
    }

    /** Explicit retry of the failed queue: stale/completed jobs cannot revive a superseded delivery. */
    public function resumeJob(SendRequestNotification $message): bool
    {
        return true === self::serialized($this->em, $message->requestId, $message->channel, static function (EntityManagerInterface $manager) use ($message): bool {
            return $manager->wrapInTransaction(static function () use ($manager, $message): bool {
                $request = self::load($manager, $message->requestId, LockMode::PESSIMISTIC_WRITE);
                if (null === $request) {
                    return false;
                }
                $job = $message->jobId ?? 'legacy';
                $phase = $request->channelJobPhase($message->channel);
                if ('legacy' === $job && null === $request->channelJob($message->channel)) {
                    return $message->resend || Request::DELIVERY_SENT !== $request->channelState($message->channel);
                }
                if ($job !== $request->channelJob($message->channel) || \in_array($phase, ['done', 'uncertain'], true)) {
                    return false;
                }
                if ('failed' === $phase) {
                    $request->markChannelJob($message->channel, $job, 'queued', \time() + self::LEASE_SECONDS);
                }

                return true;
            });
        });
    }

    public function settlePhase(int $id, string $channel, string $job, string $phase, ?string $error = null): void
    {
        self::serialized($this->em, $id, $channel, fn (EntityManagerInterface $manager) => $this->settle($manager, $id, $channel, $job, $phase, $error));
    }

    private function settle(EntityManagerInterface $manager, int $id, string $channel, string $job, string $phase, ?string $error = null): void
    {
        $manager->wrapInTransaction(function () use ($manager, $id, $channel, $job, $phase, $error): void {
            $request = self::load($manager, $id, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || $job !== $request->channelJob($channel) || \in_array($request->channelJobPhase($channel), ['done', 'uncertain'], true)) {
                return;
            }
            if ('failed' === $phase && 'processing' === $request->channelJobPhase($channel)) {
                // A duplicate envelope's old terminal event cannot release the attempt now sending.
                // Genuine attempt failures release processing to retry before worker finalization.
                return;
            }
            $request->markChannelJob($channel, $job, $phase, 'retry' === $phase ? \time() + self::LEASE_SECONDS : 0);
            if ('failed' === $phase && null === $error && Request::DELIVERY_FAILED !== $request->channelState($channel)) {
                $error = $this->locale->runDefault(static fn (): string => I18n::t('http.request_queue_failed'));
            }
            if (null !== $error) {
                $request->markChannel($channel, Request::DELIVERY_FAILED, $error);
            }
        });
    }

    /**
     * A queue failure may race with a worker for the same request, just like a messenger failure.
     *
     * @param Request::CHANNEL_* $channel
     */
    public static function recordQueueFailure(EntityManagerInterface $em, EventDispatcherInterface $events, int $requestId, string $channel, string $error, ?string $job = null, ?LoggerInterface $logger = null, bool $keepJob = false): void
    {
        self::serialized($em, $requestId, $channel, static function (EntityManagerInterface $manager, ?Connection $independent) use ($em, $events, $requestId, $channel, $error, $job, $logger, $keepJob): void {
            $request = $manager->wrapInTransaction(static function () use ($manager, $em, $independent, $requestId, $channel, $error, $job, $keepJob): ?Request {
                $request = self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE);
                if (null === $request) {
                    self::checkNotUncommitted($em, $independent, $requestId);

                    return null;
                }
                if (Request::DELIVERY_SENT === $request->channelState($channel)) {
                    return null;
                }
                if (null !== $job) {
                    $phase = $request->channelJobPhase($channel);
                    if ($job !== $request->channelJob($channel) || \in_array($phase, ['done', 'processing', 'uncertain'], true)) {
                        return null;
                    }
                    if ($keepJob && ('queued' !== $phase || [] !== $request->channelParts($channel) || $error === $request->channelError($channel))) {
                        return null;
                    }
                    if (!$keepJob) {
                        $request->markChannelJob($channel, $job, 'failed');
                    }
                }
                $request->markChannel($channel, Request::DELIVERY_FAILED, $error);
                $request->enqueueChannelAnnouncement($channel, ['delivered' => false, 'cancelled' => false, 'error' => $error]);

                return $request;
            });
            if (null !== $request) {
                self::dispatchPending($manager, $independent, $requestId, $channel, static function (RequestDelivery $event) use ($events): void {
                    $events->dispatch($event);
                }, $logger);
            }
        });
    }

    /**
     * @internal
     *
     * @template T
     *
     * @param \Closure(EntityManagerInterface, ?Connection): T $work
     *
     * @return T|null
     */
    public static function serialized(EntityManagerInterface $em, int $id, string $channel, \Closure $work): mixed
    {
        $connection = $em->getConnection();
        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }
        $lock = $id . ':' . $channel;
        if ($connection->isAutoCommit() && !$connection->isTransactionActive()) {
            return EditLock::onConnection($connection, 'request-delivery', $lock, static fn () => $work($em, null));
        }

        $independent = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        try {
            $independent->setAutoCommit(true);
            $independent->executeStatement("SET lock_timeout = '3s'");
            $searchPath = $connection->fetchOne('SHOW search_path');
            if (\is_string($searchPath)) {
                $independent->executeQuery("SELECT set_config('search_path', ?, false)", [$searchPath]);
            }
            $events = $em->getEventManager();
            $manager = new EntityManager($independent, $em->getConfiguration(), $events instanceof EventManager ? $events : null);

            return EditLock::onConnection($independent, 'request-delivery', $lock, static function () use ($em, $id, $work, $manager, $independent): mixed {
                // The new manager has no copy of the caller's enabled filters and their parameters.
                if ($em->hasFilters() && [] !== $em->getFilters()->getEnabledFilters() && !self::visible($em, $id)) {
                    return null;
                }

                return $work($manager, $independent);
            });
        } finally {
            $independent->close();
        }
    }

    private static function checkNotUncommitted(EntityManagerInterface $em, ?Connection $independent, int $requestId): void
    {
        if (null !== $independent && self::visible($em, $requestId)) {
            throw ContentException::conflict(I18n::t('http.request_delivery_requires_commit'));
        }
    }

    private static function visible(EntityManagerInterface $em, int $id): bool
    {
        return null !== $em->createQuery('SELECT r.id FROM ' . Request::class . ' r WHERE r.id = :id')
            ->setParameter('id', $id)
            ->getOneOrNullResult(Query::HYDRATE_SCALAR);
    }

    /** Publish persisted outcomes that outlived the worker which committed them. */
    public function recoverAnnouncements(int $limit = 50): int
    {
        $connection = $this->em->getConnection();
        if (!$connection->isAutoCommit() || $connection->isTransactionActive()) {
            return 0;
        }
        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }
        $count = 0;
        foreach ([Request::CHANNEL_TELEGRAM => $this->telegram, Request::CHANNEL_MAX => $this->max] as $channel => $notifier) {
            $ids = $connection->fetchFirstColumn('SELECT id FROM itk_request
                WHERE jsonb_exists(channels -> CAST(:channel AS text), \'announcements\')
                  AND COALESCE(CAST(channels -> CAST(:channel AS text) ->> \'announcementRetryAt\' AS bigint), 0) <= :now
                ORDER BY id LIMIT ' . \max(1, \min(500, $limit)), ['channel' => $channel, 'now' => \time()]);
            foreach ($ids as $id) {
                $count += (int) $this->locale->runDefault(fn () => self::serialized($this->em, (int) $id, $channel, fn (EntityManagerInterface $manager, ?Connection $independent): bool => self::dispatchPending($manager, $independent, (int) $id, $channel, $notifier->announce(...), $this->logger)));
            }
        }

        return $count;
    }

    /** @param \Closure(RequestDelivery): void $dispatch */
    private static function dispatchPending(EntityManagerInterface $manager, ?Connection $independent, int $requestId, string $channel, \Closure $dispatch, ?LoggerInterface $logger): bool
    {
        $key = \spl_object_id($manager->getConnection()) . ':' . $requestId . ':' . $channel;
        if (isset(self::$announcing[$key])) {
            return false;
        }
        self::$announcing[$key] = true;
        $sent = false;
        try {
            for ($i = 0; $i < 50; ++$i) {
                $request = self::load($manager, $requestId);
                $pending = $request?->channelAnnouncement($channel);
                if (null === $request || null === $pending) {
                    break;
                }
                try {
                    $dispatch(new RequestDelivery($request, $pending['delivered'], $pending['error'], $pending['cancelled'], $channel, $independent, durable: true));
                } catch (\Throwable $exception) {
                    $logger?->error('Request delivery listener failed for request #{id} ({channel}).', ['id' => $requestId, 'channel' => $channel, 'exceptionType' => $exception::class]);
                    $manager->wrapInTransaction(static function () use ($manager, $requestId, $channel): void {
                        self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE)?->deferChannelAnnouncement($channel, \time() + 60);
                    });
                    break;
                }
                $manager->wrapInTransaction(static function () use ($manager, $requestId, $channel): void {
                    self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE)?->acknowledgeChannelAnnouncement($channel);
                });
                $sent = true;
            }
        } finally {
            unset(self::$announcing[$key]);
        }

        return $sent;
    }

    /** A query both refreshes a managed request and returns null when another process deleted it. */
    private static function load(EntityManagerInterface $em, int $requestId, LockMode $lock = LockMode::NONE): ?Request
    {
        return $em->createQuery('SELECT r FROM ' . Request::class . ' r WHERE r.id = :id')
            ->setParameter('id', $requestId)
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lock)
            ->getOneOrNullResult();
    }
}

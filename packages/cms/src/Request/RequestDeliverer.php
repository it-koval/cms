<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Per-channel jobs reserve and claim under a short row lock; delivery and events hold no transaction. */
final class RequestDeliverer
{
    private const LEASE_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramNotifier $telegram,
        private readonly MaxNotifier $max,
        private readonly AdminLocale $locale,
    ) {
    }

    /** Reserve before dispatch. An accepted/retrying job keeps its identity until it settles. */
    public static function reserve(EntityManagerInterface $em, int $id, string $channel, bool $resend): ?string
    {
        return $em->wrapInTransaction(static function () use ($em, $id, $channel, $resend): ?string {
            $request = self::load($em, $id, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || \in_array($channel, $request->queuedChannels(), true) || (!$resend && Request::DELIVERY_SENT === $request->channelState($channel))) {
                return null;
            }
            $job = \bin2hex(\random_bytes(16));
            $request->markChannelJob($channel, $job, 'queued', \time() + self::LEASE_SECONDS);

            return $job;
        });
    }

    /** true needs no retry, false retries this job, null means the request was deleted. */
    public function deliver(int $requestId, string $channel, bool $resend, ?string $jobId = null): ?bool
    {
        // Direct callers represent a new action. Old queue jobs share a stable legacy identity.
        if (null === $jobId) {
            $jobId = self::reserve($this->em, $requestId, $channel, $resend);
            if (null === $jobId) {
                $existing = self::load($this->em, $requestId);
                if (null === $existing) {
                    return null;
                }
                if ('retry' !== $existing->channelJobPhase($channel)) {
                    return true;
                }
                $jobId = $existing->channelJob($channel);
            }
        }
        $busyUntil = 0;
        $request = $this->em->wrapInTransaction(function () use ($requestId, $channel, $resend, $jobId, &$busyUntil): ?Request {
            $request = self::load($this->em, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request) {
                return null;
            }
            if ('legacy' === $jobId && null === $request->channelJob($channel)) {
                if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel)) {
                    return null;
                }
                $request->markChannelJob($channel, $jobId, 'queued', \time() + self::LEASE_SECONDS);
            }
            if ($jobId !== $request->channelJob($channel) || \in_array($request->channelJobPhase($channel), ['done', 'failed'], true)) {
                return null;
            }
            if ('processing' === $request->channelJobPhase($channel) && $request->channelJobLease($channel) > \time()) {
                $busyUntil = $request->channelJobLease($channel);

                return null;
            }
            $request->markChannelJob($channel, $jobId, 'processing', \time() + self::LEASE_SECONDS);

            return $request;
        });
        if ($busyUntil > 0) {
            // Throw after commit: wrapInTransaction closes the entity manager on an exception.
            throw new RecoverableMessageHandlingException('Request delivery is already running.', retryDelay: ($busyUntil - \time() + 1) * 1000);
        }
        if (null === $request) {
            return null === self::load($this->em, $requestId) ? null : true;
        }
        $notifier = Request::CHANNEL_MAX === $channel ? $this->max : $this->telegram;
        try {
            $outcome = $this->locale->runDefault(static fn (): ChannelOutcome => $notifier->attempt($request, $resend));
        } catch (\Throwable $e) {
            try {
                $this->settlePhase($requestId, $channel, $jobId, 'retry');
            } catch (\Throwable) {
                // Preserve the delivery exception if state persistence also failed; the lease is bounded.
            }
            throw $e;
        }
        $recorded = $this->em->wrapInTransaction(function () use ($requestId, $channel, $jobId, $notifier, $outcome, $resend): ?Request {
            $request = self::load($this->em, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || (null !== $request->channelJob($channel) && $jobId !== $request->channelJob($channel))) {
                return null;
            }
            // A pre-job worker can replace the JSON while recording the other messenger.
            // Restore missing metadata, but never overwrite another job's identity.
            if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel) && Request::DELIVERY_SENT !== $outcome->state) {
                $request->markChannelJob($channel, $jobId, 'done');

                return null;
            }
            $notifier->record($request, $outcome, false);
            $request->markChannelJob($channel, $jobId, $outcome->isFailed() ? 'retry' : 'done', $outcome->isFailed() ? \time() + self::LEASE_SECONDS : 0);

            return $request;
        });
        if (null === $recorded) {
            return null === self::load($this->em, $requestId) ? null : true;
        }
        $this->locale->runDefault(static fn () => $notifier->announce($recorded, $outcome));

        return !$outcome->isFailed();
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
        return $this->em->wrapInTransaction(function () use ($message): bool {
            $request = self::load($this->em, $message->requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request) {
                return false;
            }
            $job = $message->jobId ?? 'legacy';
            if ('legacy' === $job && null === $request->channelJob($message->channel)) {
                if (!$message->resend && Request::DELIVERY_SENT === $request->channelState($message->channel)) {
                    return false;
                }
            } elseif ($job !== $request->channelJob($message->channel) || 'done' === $request->channelJobPhase($message->channel)) {
                return false;
            }
            if (!\in_array($message->channel, $request->queuedChannels(), true)) {
                $request->markChannelJob($message->channel, $job, 'queued', \time() + self::LEASE_SECONDS);
            }

            return true;
        });
    }

    public function settlePhase(int $id, string $channel, string $job, string $phase, ?string $error = null): void
    {
        $this->em->wrapInTransaction(function () use ($id, $channel, $job, $phase, $error): void {
            $request = self::load($this->em, $id, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || $job !== $request->channelJob($channel) || 'done' === $request->channelJobPhase($channel)) {
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

    public static function recordQueueFailure(EntityManagerInterface $em, EventDispatcherInterface $events, int $requestId, string $channel, string $error, ?string $job = null): void
    {
        $request = $em->wrapInTransaction(static function () use ($em, $requestId, $channel, $error, $job): ?Request {
            $request = self::load($em, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || Request::DELIVERY_SENT === $request->channelState($channel)) {
                return null;
            }
            if (null !== $job) {
                if ($job !== $request->channelJob($channel) || 'done' === $request->channelJobPhase($channel)) {
                    return null;
                }
                $request->markChannelJob($channel, $job, 'failed');
            }
            $request->markChannel($channel, Request::DELIVERY_FAILED, $error);

            return $request;
        });
        if (null !== $request) {
            try {
                $events->dispatch(new RequestDelivery($request, false, $error, false, $channel));
            } catch (\Throwable) {
                // Delivery state is committed; extension notifications are best effort.
            }
        }
    }

    private static function load(EntityManagerInterface $em, int $requestId, LockMode $lock = LockMode::NONE): ?Request
    {
        return $em->createQuery('SELECT r FROM ' . Request::class . ' r WHERE r.id = :id')
            ->setParameter('id', $requestId)
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode($lock)
            ->getOneOrNullResult();
    }
}

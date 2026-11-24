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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Delivers a saved request to one messenger and records the outcome in the request. The one place where the queue handler and «Отправить снова» do that.
 * One session lock reserves the request and channel from the first read through the outcome event. The network call holds no row lock.
 * A short transaction refreshes the row and saves the outcome, including when the caller has its own transaction:
 * Telegram and MAX are delivered by separate messages, possibly by two workers at once, and each one writes the delivery state of the whole request.
 * A messenger that already has the request is not sent it again by a queue retry (only «Отправить снова» does that).
 * The next worker on the same channel sees the committed outcome before deciding whether to send.
 * A long request is several messages (MessageParts), a second or more apart, and is delivered only when all went out. A retry sends all of them
 * from the first: the chat may then see the first ones again, but a request that lacks its end is never taken for delivered.
 */
final class RequestDeliverer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramNotifier $telegram,
        private readonly MaxNotifier $max,
        private readonly AdminLocale $locale,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param Request::CHANNEL_* $channel
     *
     * @return bool|null true when the request needs no retry, false when it does, null when it is gone (deleted before it was sent)
     */
    public function deliver(int $requestId, string $channel, bool $resend): ?bool
    {
        return self::serialized($this->em, $requestId, $channel, fn (EntityManagerInterface $manager, ?Connection $independent): ?bool => $this->sendAndRecord($manager, $independent, $requestId, $channel, $resend));
    }

    /** @param Request::CHANNEL_* $channel */
    private function sendAndRecord(EntityManagerInterface $manager, ?Connection $independent, int $requestId, string $channel, bool $resend): ?bool
    {
        // Verify that an ambient transaction is not holding the request row before doing irreversible network I/O.
        $request = null === $independent
            ? self::load($manager, $requestId)
            : $independent->transactional(static fn (): ?Request => self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE));
        if (null === $request) {
            self::checkNotUncommitted($this->em, $independent, $requestId);

            return null;
        }
        if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel)) {
            return true;
        }
        $notifier = Request::CHANNEL_MAX === $channel ? $this->max : $this->telegram;
        // a chat is read by many, in admin_locale: also when a sync transport runs this inside the request of one person
        $outcome = $this->locale->runDefault(static fn (): ChannelOutcome => $notifier->attempt($request, $resend));

        $announce = null;
        $saved = $manager->wrapInTransaction(static function () use ($manager, $independent, $requestId, $notifier, $outcome, $channel, $resend, &$announce): ?bool {
            $request = self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request) {
                return null;
            }
            if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel) && Request::DELIVERY_SENT !== $outcome->state) {
                return true;
            }

            return $notifier->record($request, $outcome, static function (\Closure $dispatch) use (&$announce): void {
                $announce = $dispatch;
            }, $independent);
        });
        if (null !== $announce) {
            $this->locale->runDefault(fn () => self::announce($announce, $this->logger, $requestId, $channel));
        }

        return $saved;
    }

    /**
     * A queue failure may race with a worker for the same request, just like a messenger failure.
     *
     * @param Request::CHANNEL_* $channel
     */
    public static function recordQueueFailure(EntityManagerInterface $em, EventDispatcherInterface $events, int $requestId, string $channel, string $error, ?LoggerInterface $logger = null): void
    {
        self::serialized($em, $requestId, $channel, static function (EntityManagerInterface $manager, ?Connection $independent) use ($em, $events, $requestId, $channel, $error, $logger): void {
            $request = $manager->wrapInTransaction(static function () use ($manager, $em, $independent, $requestId, $channel, $error): ?Request {
                $request = self::load($manager, $requestId, LockMode::PESSIMISTIC_WRITE);
                if (null === $request) {
                    self::checkNotUncommitted($em, $independent, $requestId);

                    return null;
                }
                if (Request::DELIVERY_SENT === $request->channelState($channel)) {
                    return null;
                }
                $request->markChannel($channel, Request::DELIVERY_FAILED, $error);

                return $request;
            });
            if (null !== $request) {
                self::announce(static fn () => $events->dispatch(new RequestDelivery($request, false, $error, false, $channel, $independent)), $logger, $requestId, $channel);
            }
        });
    }

    /**
     * @template T
     *
     * @param \Closure(EntityManagerInterface, ?Connection): T $work
     *
     * @return T|null
     */
    private static function serialized(EntityManagerInterface $em, int $id, string $channel, \Closure $work): mixed
    {
        $connection = $em->getConnection();
        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }
        $lock = $id . ':' . $channel;
        if (!$connection->isTransactionActive()) {
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

    private static function announce(\Closure $dispatch, ?LoggerInterface $logger, int $requestId, string $channel): void
    {
        try {
            $dispatch();
        } catch (\Throwable $exception) {
            $logger?->error('Request delivery listener failed for request #{id} ({channel}).', ['id' => $requestId, 'channel' => $channel, 'exception' => $exception]);
        }
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

<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\I18n\AdminLocale;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Delivers a saved request to one messenger and records the outcome in the request. The one place where the queue handler and «Отправить снова» do that.
 * The message goes out first, outside any transaction, so a slow or dead messenger holds no lock. Then, in a short transaction, the row is locked, refreshed and only the state of this messenger is written:
 * Telegram and MAX are delivered by separate messages, possibly by two workers at once, and each one writes the delivery state of the whole request.
 * A messenger that already has the request is not sent it again by a queue retry (only «Отправить снова» does that).
 * Two workers of the same messenger (a retry and «Отправить снова» at once) can still both send before either records; the request then reaches the chat twice.
 * A queue retry that failed after another worker recorded the delivery leaves it delivered.
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
        $request = self::load($this->em, $requestId);
        if (null === $request) {
            return null;
        }
        if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel)) {
            return true;
        }
        $notifier = Request::CHANNEL_MAX === $channel ? $this->max : $this->telegram;
        // a chat is read by many, in admin_locale: also when a sync transport runs this inside the request of one person
        $outcome = $this->locale->runDefault(static fn (): ChannelOutcome => $notifier->attempt($request, $resend));

        $announce = null;
        $saved = $this->em->wrapInTransaction(function () use ($requestId, $notifier, $outcome, $channel, $resend, &$announce): ?bool {
            $request = self::load($this->em, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request) {
                return null;
            }
            if (!$resend && Request::DELIVERY_SENT === $request->channelState($channel) && Request::DELIVERY_SENT !== $outcome->state) {
                return true;
            }

            return $notifier->record($request, $outcome, static function (\Closure $dispatch) use (&$announce): void {
                $announce = $dispatch;
            });
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
        $request = $em->wrapInTransaction(static function () use ($em, $requestId, $channel, $error): ?Request {
            $request = self::load($em, $requestId, LockMode::PESSIMISTIC_WRITE);
            if (null === $request || Request::DELIVERY_SENT === $request->channelState($channel)) {
                return null;
            }
            $request->markChannel($channel, Request::DELIVERY_FAILED, $error);

            return $request;
        });
        if (null !== $request) {
            self::announce(static fn () => $events->dispatch(new RequestDelivery($request, false, $error, false, $channel)), $logger, $requestId, $channel);
        }
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

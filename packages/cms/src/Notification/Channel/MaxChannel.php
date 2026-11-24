<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Request\RequestQueue;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * MAX for the requests of the site forms, as TelegramChannel is for Telegram: a `request.new` notification queues the delivery of its own (SendRequestNotification, channel max),
 * so a refused MAX message is retried alone and never touches the Telegram one. When the queue itself is unavailable the MAX delivery of the request is marked «не доставлена» at once.
 * A site without a connected MAX (no bundle, no token, switched off) queues nothing: the MAX delivery stays «pending». Other notifications do not go to MAX.
 *
 * @internal
 */
final class MaxChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
        private readonly MaxNotifier $max,
        private readonly ?AdminLocale $locale = null,
        private readonly ?RequestQueue $queue = null,
    ) {
    }

    public function name(): string
    {
        return 'max';
    }

    public function supports(Notification $notification): bool
    {
        return Notification::REQUEST_NEW === $notification->type && isset($notification->payload['requestId']);
    }

    public function send(Notification $notification): void
    {
        if (!$this->max->isActive()) {
            return;
        }
        $queue = $this->queue ?? new RequestQueue($this->em, $this->bus, $this->logger, $this->events, $this->locale);
        $queue->enqueue((int) $notification->payload['requestId'], Request::CHANNEL_MAX);
    }
}

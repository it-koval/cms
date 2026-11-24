<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Request\RequestDeliverer;
use Itk\Cms\Request\SendRequestNotification;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * MAX for the requests of the site forms, as TelegramChannel is for Telegram: a `request.new` notification queues the delivery of its own (SendRequestNotification, channel max),
 * so a refused MAX message is retried alone and never touches the Telegram one. When the queue itself is unavailable the MAX delivery of the request is marked «не доставлена» at once.
 * A site without a connected MAX (no bundle, no token, switched off) queues nothing: the MAX delivery stays «pending». Other notifications do not go to MAX.
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
        $id = (int) $notification->payload['requestId'];
        try {
            $this->bus->dispatch(new SendRequestNotification($id, false, Request::CHANNEL_MAX));
        } catch (\Throwable $e) {
            // the request is saved; it can be sent again from the admin or with itk:requests:resend
            // the text is kept and shown to the staff in the bell, so it is written in the language of the site, not of the route that failed
            $write = static fn (): string => I18n::t('http.max_queue_failed', ['reason' => $e->getMessage()]);
            $error = null !== $this->locale ? $this->locale->runDefault($write) : $write();
            $this->logger->error('Could not queue MAX notification for request #{id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);
            RequestDeliverer::recordQueueFailure($this->em, $this->events, $id, Request::CHANNEL_MAX, $error, $this->logger);
        }
    }
}

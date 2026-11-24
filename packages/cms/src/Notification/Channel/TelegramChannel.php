<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;
use Itk\Cms\Request\RequestDeliverer;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Request\TelegramNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Telegram for the requests of the site forms. A `request.new` notification queues the delivery (Messenger, async): the worker then sends the text
 * through Request\TelegramNotifier, marks the request «доставлена» / «не доставлена» and retries — exactly as before the notification contract.
 * When the queue itself is unavailable the request is marked «не доставлена» at once (itk:requests:resend or «Отправить снова» sends it later).
 * A site without a connected Telegram (or with it switched off) queues nothing: the request stays «pending», a queue that is down is not its failure.
 * Other notifications do not go to Telegram.
 *
 * @internal
 */
final class TelegramChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
        private readonly TelegramNotifier $telegram,
        private readonly ?AdminLocale $locale = null,
    ) {
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function supports(Notification $notification): bool
    {
        return Notification::REQUEST_NEW === $notification->type && isset($notification->payload['requestId']);
    }

    public function send(Notification $notification): void
    {
        if (!$this->telegram->isActive()) {
            return;
        }
        $id = (int) $notification->payload['requestId'];
        $job = RequestDeliverer::reserve($this->em, $id, Request::CHANNEL_TELEGRAM, false);
        if (null === $job) {
            return;
        }
        try {
            $this->bus->dispatch(new SendRequestNotification($id, false, Request::CHANNEL_TELEGRAM, $job));
        } catch (\Throwable $e) {
            // the request is saved; it can be sent again from the admin or with itk:requests:resend
            // the text is kept and shown to the staff in the bell, so it is written in the language of the site, not of the route that failed
            $write = static fn (): string => I18n::t('http.telegram_queue_failed', ['reason' => $e->getMessage()]);
            $error = null !== $this->locale ? $this->locale->runDefault($write) : $write();
            $this->logger->error('Could not queue Telegram notification for request #{id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);
            RequestDeliverer::recordQueueFailure($this->em, $this->events, $id, Request::CHANNEL_TELEGRAM, $error, $job, $this->logger);
        }
    }
}

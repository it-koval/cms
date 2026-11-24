<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;
use Itk\Cms\Request\RequestQueue;
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
        private readonly ?RequestQueue $queue = null,
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
        $queue = $this->queue ?? new RequestQueue($this->em, $this->bus, $this->logger, $this->events, $this->locale);
        $queue->enqueue((int) $notification->payload['requestId'], Request::CHANNEL_TELEGRAM);
    }
}

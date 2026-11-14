<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A request did not reach a messenger (Telegram or MAX, each with its own notification) → a notification for those who can send it again; it did later, or there is no such messenger to send it to any more → that notification is taken back.
 * The bell must never get in the way of the delivery itself, so a failure here is only logged.
 *
 * @internal
 */
#[AsEventListener]
final class RequestDeliveryListener
{
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly NotificationInbox $inbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestDelivery $event): void
    {
        $r = $event->request;
        [$type, $titleKey] = Request::CHANNEL_MAX === $event->channel
            ? [Notification::MAX_FAILED, 'i18n.notification_max_failed']
            : [Notification::TELEGRAM_FAILED, 'i18n.notification_telegram_failed'];
        $key = $type . ':' . $r->id;
        try {
            if ($event->delivered || $event->cancelled) {
                $this->inbox->resolve($key);

                return;
            }
            $this->notifier->notify(Notification::translated(
                type: $type,
                titleKey: $titleKey,
                titleParams: ['id' => $r->id],
                body: \mb_substr(\trim((string) $event->error), 0, 500),
                link: '/requests/' . $r->id,
                level: Notification::ERROR,
                audience: 'requests:edit',
                payload: ['requestId' => $r->id, 'form' => $r->form],
                key: $key,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Could not update the notification about request #{id}: {error}', ['id' => $r->id, 'error' => $e->getMessage()]);
        }
    }
}

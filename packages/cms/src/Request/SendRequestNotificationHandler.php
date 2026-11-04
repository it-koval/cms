<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\AdminLocale;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** @internal */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class SendRequestNotificationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TelegramNotifier $notifier,
        private readonly AdminLocale $locale,
    ) {
    }

    public function __invoke(SendRequestNotification $message): void
    {
        $request = $this->em->find(Request::class, $message->requestId);
        if (null === $request) {
            return; // deleted before it was sent
        }
        // a chat is read by many, in admin_locale: also when a sync transport runs this inside the request of one person
        $delivered = $this->locale->runDefault(fn (): bool => $this->notifier->notify($request, $message->resend));
        $this->em->flush();
        if (!$delivered) {
            // Messenger retries (retry_strategy of the async transport); the state stays «failed» until a delivery works
            throw new RequestNotDeliveredException(\sprintf('Request #%d was not delivered to Telegram.', $message->requestId));
        }
    }
}

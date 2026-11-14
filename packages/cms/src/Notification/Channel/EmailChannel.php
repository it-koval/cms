<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;
use Psr\Log\LoggerInterface;

/**
 * E-mail for the requests of the site forms. A `request.new` notification becomes one letter per address of «Настройки → Уведомления → Письма о новых заявках»
 * (the form's own list, else the list of «остальные формы»), put into the queue; the worker sends it through the mail server of «Настройки → Почта».
 * Nothing is sent while mail is not configured or no address is given. A letter that fails for good shows in the bell and on «Состояние сайта» (MailFailureListener).
 * Telegram is a separate channel and works as before. Other notifications do not go by e-mail.
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
        private readonly SiteMailer $mailer,
        private readonly MailComposer $composer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'email';
    }

    public function supports(Notification $notification): bool
    {
        return Notification::REQUEST_NEW === $notification->type && isset($notification->payload['requestId']);
    }

    public function send(Notification $notification): void
    {
        $id = (int) $notification->payload['requestId'];
        $request = $this->em->find(Request::class, $id);
        if (null === $request) {
            return;
        }
        $to = $this->settings->mailRecipientsFor($request->form);
        if ([] === $to) {
            return;
        }
        if (!$this->mailer->isConfigured()) {
            $this->logger->warning('Request #{id}: addresses for letters are set, but mail is not configured.', ['id' => $id]);

            return;
        }
        foreach ($to as $address) {
            try {
                $this->mailer->queue($this->composer->newRequest($request, $address));
            } catch (\Throwable $e) {
                // the request is saved and Telegram goes its own way; the others on the list still get their letter
                $this->logger->error('Could not queue the letter about request #{id}: {error}', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }
}

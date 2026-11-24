<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Notification\NotificationInbox;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends a queued letter. A failure is thrown up with a cleaned text, so Messenger retries by the retry_strategy of the transport and, when the retries end, puts the letter
 * into the failure transport («Состояние сайта» shows it) and MailFailureListener tells the bell.
 *
 * @internal
 */
#[AsMessageHandler]
final class SendMailHandler
{
    public function __construct(
        private readonly SiteMailer $mailer,
        private readonly NotificationInbox $inbox,
    ) {
    }

    public function __invoke(SendMail $mail): void
    {
        $this->mailer->deliver($mail);
        if (SendMail::REQUEST === $mail->kind && null !== $mail->ref) {
            try {
                $this->inbox->resolve(MailFailureListener::key($mail)); // it went out in the end: the bell takes the warning back
            } catch (\Throwable) {
            }
        }
    }
}

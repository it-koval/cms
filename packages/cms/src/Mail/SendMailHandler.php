<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

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
        private readonly RequestMailWarnings $warnings,
        private readonly RequestMailCheckpoint $checkpoint,
    ) {
    }

    public function __invoke(SendMail $mail): void
    {
        // Pre-0.3 queued password letters contain raw tokens. They are superseded by delivery jobs.
        if (\in_array($mail->kind, [SendMail::RESET, SendMail::INVITE], true)) {
            return;
        }
        $this->checkpoint->deliver($mail, fn () => $this->mailer->deliver($mail));
        if (SendMail::REQUEST === $mail->kind && null !== $mail->ref) {
            try {
                $this->warnings->accepted($mail->ref, $mail->to); // Also repairs warnings when the checkpoint is already done.
            } catch (\Throwable) {
            }
        }
    }
}

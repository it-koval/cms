<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotifierInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * A letter failed for good (the retries of the queue are over): a notification for the bell of those who can fix it. A request letter goes to those who handle requests,
 * the others (password links, the test letter) to those who may edit «Почта». The text is the cleaned one of MailDeliveryException; any other error gets a general sentence.
 * The bell must not get in the way of the queue, so a failure here is only logged.
 *
 * @internal
 */
#[AsEventListener]
final class MailFailureListener
{
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $mail = $event->getEnvelope()->getMessage();
        if (!$mail instanceof SendMail || $event->willRetry()) {
            return;
        }
        $reason = null;
        for ($e = $event->getThrowable(), $i = 0; null !== $e && $i < 10; $e = $e->getPrevious(), ++$i) {
            if ($e instanceof MailDeliveryException) {
                $reason = $e->getMessage();

                break;
            }
        }
        $request = SendMail::REQUEST === $mail->kind && null !== $mail->ref;
        try {
            $this->notifier->notify(Notification::translated(
                type: Notification::MAIL_FAILED,
                titleKey: $request ? 'i18n.notification_mail_failed_request' : 'i18n.notification_mail_failed',
                titleParams: $request ? ['id' => $mail->ref] : ['subject' => self::subject($mail)],
                body: \mb_substr((string) $reason, 0, 500),
                bodyKey: null === $reason ? 'i18n.notification_mail_refused' : null,
                link: $request ? '/requests/' . $mail->ref : '/settings/mail',
                level: Notification::ERROR,
                audience: $request ? 'requests:edit' : 'set:mail:edit',
                payload: ['kind' => $mail->kind] + ($request ? ['requestId' => (int) $mail->ref] : []),
                key: self::key($mail),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Could not tell about a failed {kind} letter: {error}', ['kind' => $mail->kind, 'error' => $e->getMessage()]);
        }
    }

    /** One notification for a matter: the request, else the kind of the letter and its subject. */
    public static function key(SendMail $mail): string
    {
        return Notification::MAIL_FAILED . ':' . ($mail->ref ?? $mail->kind . ':' . \substr(\hash('sha256', $mail->subject), 0, 12));
    }

    private static function subject(SendMail $mail): string
    {
        return \mb_substr($mail->subject, 0, 120);
    }
}

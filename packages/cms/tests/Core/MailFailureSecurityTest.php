<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Mail\MailDeliveryException;
use Itk\Cms\Mail\MailFailureListener;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotifierInterface;
use Itk\Cms\Security\DeliverPasswordLink;
use Itk\Cms\Security\RequestPasswordLink;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class MailFailureSecurityTest extends TestCase
{
    public function testFinalAccountDeliveryFailuresReachTheMailAdministratorsWithoutAccountData(): void
    {
        foreach ([new RequestPasswordLink('fixture@example.com', 'https://configured.example', true), new DeliverPasswordLink('fixture-user', 'fixture@example.com', 'fixture-hash', SendMail::INVITE, 'P7D', 'https://configured.example')] as $message) {
            $notifier = $this->createMock(NotifierInterface::class);
            $notifier->expects(self::once())->method('notify')->with(self::callback(static function (Notification $notification): bool {
                self::assertSame('set:mail:edit', $notification->audience);
                self::assertSame('/settings/mail', $notification->link);
                self::assertStringNotContainsString('fixture@example.com', \serialize($notification));
                self::assertStringNotContainsString('fixture-hash', \serialize($notification));
                self::assertSame('SMTP fixture refusal', $notification->body);

                return true;
            }));
            (new MailFailureListener($notifier, new NullLogger()))(new WorkerMessageFailedEvent(new Envelope($message), 'async', new MailDeliveryException('SMTP fixture refusal')));
        }
    }

    public function testAccountDeliveryRetriesDoNotNotifyBeforeTheFinalFailure(): void
    {
        $event = new WorkerMessageFailedEvent(new Envelope(new RequestPasswordLink('fixture@example.com', 'https://configured.example', true)), 'async', new \RuntimeException('Fixture'));
        $event->setForRetry();
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('notify');
        (new MailFailureListener($notifier, new NullLogger()))($event);
    }

    public function testRequestFailureKeysArePerChannelRequestAndNormalizedRecipientWithoutAddressData(): void
    {
        $mail = static fn (string $to, string $ref = '42', string $kind = SendMail::REQUEST): SendMail => new SendMail($to, 'Fixture', 'Body', '<p>Body</p>', $kind, $ref);
        $first = MailFailureListener::key($mail(' First@Example.com '));
        self::assertSame($first, MailFailureListener::key($mail('first@example.com')));
        self::assertNotSame($first, MailFailureListener::key($mail('second@example.com')));
        self::assertNotSame($first, MailFailureListener::key($mail('first@example.com', '43')));
        self::assertNotSame($first, MailFailureListener::key($mail('first@example.com', '42', SendMail::TEST)));
        self::assertStringStartsWith(Notification::MAIL_FAILED . ':42:', $first);
        self::assertStringNotContainsString('example.com', $first);
        self::assertLessThanOrEqual(120, \strlen($first));
    }
}

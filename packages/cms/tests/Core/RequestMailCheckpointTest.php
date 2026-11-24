<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Mail\MailDeliveryException;
use Itk\Cms\Mail\MailDeliveryUnknownException;
use Itk\Cms\Mail\MailerFactory;
use Itk\Cms\Mail\MailFailureListener;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\MailStatus;
use Itk\Cms\Mail\RequestMailCheckpoint;
use Itk\Cms\Mail\RequestMailTransportGuard;
use Itk\Cms\Mail\RequestMailWarnings;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SendMailHandler;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotifierInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Mime\Email;

final class RequestMailCheckpointTest extends KernelTestCase
{
    private Request $request;
    private mixed $previous;
    private ?\Closure $listener = null;
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $settings = $c->get(MailSettings::class);
        $db = $c->get(Connection::class);
        $this->previous = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']);
        $settings->save(['enabled' => true, 'host' => 'smtp.example.test', 'port' => 25, 'encryption' => 'none', 'username' => '', 'clearPassword' => true, 'fromAddress' => 'site@example.com', 'fromName' => 'Test']);
        $this->request = new Request('call');
        $em->persist($this->request);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        if (null !== $this->listener) {
            $c->get('event_dispatcher')->removeListener(MessageEvent::class, $this->listener);
        }
        $db = $c->get(Connection::class);
        foreach ($this->keys as $key) {
            $db->executeStatement('DELETE FROM itk_request_mail_delivery WHERE delivery_key = ?', [$key]);
        }
        $db->executeStatement('DELETE FROM itk_request WHERE id = ?', [$this->request->id]);
        $db->executeStatement('DELETE FROM itk_notification WHERE dedupe_key IN (?, ?) OR dedupe_key LIKE ?', ['mail.failed:' . $this->request->id, 'mail_failed:' . $this->request->id, 'mail.failed:' . $this->request->id . ':%']);
        $db->executeStatement('DELETE FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']);
        if (false !== $this->previous) {
            $db->insert('itk_setting', ['setting_key' => MailSettings::KEY, 'locale' => '*', 'setting_value' => $this->previous]);
        }
        parent::tearDown();
    }

    private function mail(string $to = 'person@example.com'): SendMail
    {
        $mail = new SendMail($to, 'Request', 'Body', '<p>Body</p>', SendMail::REQUEST, (string) $this->request->id);
        $this->keys[] = RequestMailCheckpoint::key($mail);

        return $mail;
    }

    private function dispatch(SendMail $mail): void
    {
        self::getContainer()->get('messenger.default_bus')->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
    }

    private function transport(\Closure $listener): void
    {
        $this->listener = $listener;
        self::getContainer()->get('event_dispatcher')->addListener(MessageEvent::class, $listener, 1000);
    }

    #[Test]
    public function duplicateRequestMailToTheSameNormalizedRecipientIsSentOnlyOnce(): void
    {
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            ++$attempts;
            $event->reject();
        });
        foreach (['person@example.com', 'PERSON@example.com', 'other@example.com'] as $to) {
            $this->dispatch($this->mail($to));
        }
        self::assertSame(2, $attempts, 'same request and recipient stays delivered across duplicate envelopes');
    }

    #[Test]
    public function oneRecipientsSuccessOrDuplicateDoesNotHideAnotherRecipientsFailure(): void
    {
        $a = $this->mail('first@example.com');
        $b = $this->mail('second@example.com');
        $c = self::getContainer();
        $listener = new MailFailureListener($c->get(NotifierInterface::class), new NullLogger());
        foreach ([$a, $b] as $mail) {
            $listener(new WorkerMessageFailedEvent(new Envelope($mail), 'async', new MailDeliveryException('SMTP fixture failure')));
        }
        $db = $c->get(Connection::class);
        $exists = static fn (SendMail $mail): bool => (bool) $db->fetchOne('SELECT 1 FROM itk_notification WHERE dedupe_key = ?', [MailFailureListener::key($mail)]);
        self::assertNotSame(MailFailureListener::key($a), MailFailureListener::key($b));
        self::assertTrue($exists($a));
        self::assertTrue($exists($b));
        $this->transport(static fn (MessageEvent $event) => $event->reject());
        $this->dispatch($b);
        self::assertTrue($exists($a), 'a success clears only the same recipient warning');
        self::assertFalse($exists($b));
        $listener(new WorkerMessageFailedEvent(new Envelope($b), 'async', new MailDeliveryException('Late fixture failure')));
        $this->dispatch($b);
        self::assertTrue($exists($a));
        self::assertFalse($exists($b), 'a done checkpoint repairs its own warning on replay');
        $this->dispatch($a);
        self::assertFalse($exists($a), 'a newly successful send clears its own warning');
        self::assertFalse($exists($b));
    }

    #[Test]
    public function manualConfirmationOfDeliveryClearsOnlyTheConfirmedRecipientsWarning(): void
    {
        $a = $this->mail('first@example.com');
        $b = $this->mail('second@example.com');
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $listener = new MailFailureListener($c->get(NotifierInterface::class), new NullLogger());
        foreach ([$a, $b] as $mail) {
            $db->insert('itk_request_mail_delivery', ['delivery_key' => RequestMailCheckpoint::key($mail), 'state' => 'unknown']);
            $listener(new WorkerMessageFailedEvent(new Envelope($mail), 'async', new MailDeliveryUnknownException('SMTP fixture unknown')));
        }
        $exists = static fn (SendMail $mail): bool => (bool) $db->fetchOne('SELECT 1 FROM itk_notification WHERE dedupe_key = ?', [MailFailureListener::key($mail)]);
        self::assertTrue($exists($a));
        self::assertTrue($exists($b));
        $command = new CommandTester((new Application(self::$kernel))->find('itk:requests:mail:resolve'));
        self::assertSame(0, $command->execute(['request' => (string) $this->request->id, 'recipient' => \strtoupper($a->to), 'outcome' => 'sent']));
        self::assertFalse($exists($a), 'verified SMTP acceptance settles the warning without requiring a resend');
        self::assertTrue($exists($b), 'another recipient remains unresolved');
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            ++$attempts;
            $event->reject();
        });
        $this->dispatch($a);
        self::assertSame(0, $attempts, 'verified acceptance still prevents duplicate delivery');
        self::assertSame(0, $command->execute(['request' => (string) $this->request->id, 'recipient' => $b->to, 'outcome' => 'rejected']));
        self::assertTrue($exists($b), 'a verified rejection leaves the warning until this recipient is sent successfully');
    }

    #[Test]
    #[DataProvider('legacyMailRoutes')]
    public function legacyWarningsRequireOperatorVerificationEvenAfterKnownRecipientsAreDone(bool $routeChanged, bool $hasCheckpoint): void
    {
        $a = $this->mail('first@example.com');
        $b = $this->mail('second@example.com');
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $routesKey = ['setting_key' => 'itk:mailRoutes', 'locale' => '*'];
        $legacyRoutesKey = ['setting_key' => 'mailRoutes', 'locale' => '*'];
        $previous = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', \array_values($routesKey));
        $legacyPrevious = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', \array_values($legacyRoutesKey));
        $settings = $c->get(SettingsService::class);
        $original = ['call' => $a->to . ',' . $b->to];
        $db->executeStatement('INSERT INTO itk_setting (setting_key, locale, setting_value) VALUES (?, ?, ?) ON CONFLICT (setting_key, locale) DO UPDATE SET setting_value = EXCLUDED.setting_value', ['mailRoutes', '*', \json_encode($original, \JSON_THROW_ON_ERROR)]);
        $settings->set('itk:mailRoutes', $routeChanged ? ['call' => $a->to] : $original);
        try {
            $command = new CommandTester((new Application(self::$kernel))->find('itk:requests:mail:resolve'));
            if (!$hasCheckpoint) {
                self::assertSame(1, $command->execute(['request' => $b->ref, 'recipient' => $b->to, 'outcome' => 'sent']), 'a missing checkpoint requires a legacy warning');
            }
            $legacy = ['mail_failed:' . $this->request->id, 'mail.failed:' . $this->request->id];
            foreach ($legacy as $key) {
                $c->get(NotifierInterface::class)->notify(new Notification(Notification::MAIL_FAILED, 'Legacy warning', key: $key));
            }
            $count = static fn (): int => (int) $db->fetchOne('SELECT COUNT(*) FROM itk_notification WHERE dedupe_key IN (?, ?)', $legacy);
            self::assertSame(2, $count());
            if ($hasCheckpoint) {
                $db->insert('itk_request_mail_delivery', ['delivery_key' => RequestMailCheckpoint::key($b), 'state' => 'unknown']);
            }
            $attempts = 0;
            $this->transport(static function (MessageEvent $event) use (&$attempts): void {
                ++$attempts;
                $event->reject();
            });
            $this->dispatch($a);
            self::assertSame(2, $count(), 'one recipient still has an unresolved delivery');

            if (!$hasCheckpoint) {
                self::assertSame(1, $command->execute(['request' => $b->ref, 'recipient' => $b->to, 'outcome' => 'rejected']));
                self::assertSame(2, $count());
            }
            self::assertSame(0, $command->execute(['request' => $b->ref, 'recipient' => $b->to, 'outcome' => 'sent']));
            self::assertSame(2, $count(), 'routes do not prove the complete historical recipient list');
            self::assertSame('done', $db->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($b)]));
            $this->dispatch($b);
            self::assertSame(1, $attempts, 'verified legacy acceptance prevents duplicate delivery');
            self::assertSame(0, $command->execute(['request' => $b->ref, '--legacy-checked' => true]));
            self::assertSame(0, $count(), 'explicit verification closes both aggregate aliases');
        } finally {
            $db->delete('itk_setting', $routesKey);
            if (false !== $previous) {
                $db->insert('itk_setting', $routesKey + ['setting_value' => $previous]);
            }
            $db->delete('itk_setting', $legacyRoutesKey);
            if (false !== $legacyPrevious) {
                $db->insert('itk_setting', $legacyRoutesKey + ['setting_value' => $legacyPrevious]);
            }
            $settings->reset();
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function legacyMailRoutes(): iterable
    {
        yield 'unchanged route' => [false, true];
        yield 'one recipient removed after upgrade' => [true, true];
        yield 'legacy send has no checkpoint' => [false, false];
        yield 'legacy send has no checkpoint and recipient was removed after upgrade' => [true, false];
    }

    #[Test]
    public function aRecipientRemovedBeforeUpgradeCannotBeForgottenByADoneReplay(): void
    {
        $b = $this->mail('second@example.com');
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $settings = $c->get(SettingsService::class);
        $rows = [];
        foreach (['mailRoutes', 'itk:mailRoutes'] as $key) {
            $rows[$key] = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, '*']);
            // A failed at first@example.com, then was removed before either route snapshot existed.
            $db->executeStatement('INSERT INTO itk_setting (setting_key, locale, setting_value) VALUES (?, ?, ?) ON CONFLICT (setting_key, locale) DO UPDATE SET setting_value = EXCLUDED.setting_value', [$key, '*', \json_encode(['call' => $b->to], \JSON_THROW_ON_ERROR)]);
        }
        $settings->reset();
        $legacy = ['mail_failed:' . $b->ref, 'mail.failed:' . $b->ref];
        try {
            foreach ($legacy as $key) {
                $c->get(NotifierInterface::class)->notify(new Notification(Notification::MAIL_FAILED, 'Legacy warning', key: $key));
            }
            $db->insert('itk_request_mail_delivery', ['delivery_key' => RequestMailCheckpoint::key($b), 'state' => 'done']);
            $this->transport(static fn (MessageEvent $event) => self::fail('done recipient must not send'));
            $this->dispatch($b);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_notification WHERE dedupe_key IN (?, ?)', $legacy), 'B acceptance cannot settle an unknown historical recipient A');
        } finally {
            foreach ($rows as $key => $value) {
                $db->delete('itk_setting', ['setting_key' => $key, 'locale' => '*']);
                if (false !== $value) {
                    $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => $value]);
                }
            }
            $settings->reset();
        }
    }

    #[Test]
    public function checkingALegacyAggregateDoesNotAlterRecipientWarningsOrCheckpoints(): void
    {
        $mail = $this->mail();
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $listener = new MailFailureListener($c->get(NotifierInterface::class), new NullLogger());
        $listener(new WorkerMessageFailedEvent(new Envelope($mail), 'async', new MailDeliveryUnknownException('SMTP fixture unknown')));
        $db->insert('itk_request_mail_delivery', ['delivery_key' => RequestMailCheckpoint::key($mail), 'state' => 'unknown']);
        $aggregate = 'mail_failed:' . $mail->ref;
        $c->get(NotifierInterface::class)->notify(new Notification(Notification::MAIL_FAILED, 'Legacy warning', key: $aggregate));
        $command = new CommandTester((new Application(self::$kernel))->find('itk:requests:mail:resolve'));
        self::assertSame(2, $command->execute(['request' => $mail->ref, 'recipient' => $mail->to, 'outcome' => 'sent', '--legacy-checked' => true]), 'aggregate verification and recipient resolution cannot be mixed');
        self::assertNotFalse($db->fetchOne('SELECT 1 FROM itk_notification WHERE dedupe_key = ?', [$aggregate]));
        self::assertSame(0, $command->execute(['request' => $mail->ref, '--legacy-checked' => true]));
        self::assertFalse($db->fetchOne('SELECT 1 FROM itk_notification WHERE dedupe_key = ?', [$aggregate]));
        self::assertNotFalse($db->fetchOne('SELECT 1 FROM itk_notification WHERE dedupe_key = ?', [MailFailureListener::key($mail)]));
        self::assertSame('unknown', $db->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        self::assertSame(1, $command->execute(['request' => $mail->ref, '--legacy-checked' => true]), 'a missing aggregate does not report a successful change');
    }

    #[Test]
    public function rejectionAlwaysShowsTheAddressedRetryAndOnlyNamesAKnownFailedEnvelope(): void
    {
        $mail = $this->mail();
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $key = RequestMailCheckpoint::key($mail);
        $db->insert('itk_request_mail_delivery', ['delivery_key' => $key, 'state' => 'unknown']);
        $app = new Application(self::$kernel);
        $command = new CommandTester($app->find('itk:requests:mail:resolve'));
        $input = ['request' => $mail->ref, 'recipient' => $mail->to, 'outcome' => 'rejected'];
        $targeted = 'itk:requests:resend ' . $mail->ref . ' --channel=mail --recipient=' . \escapeshellarg($mail->to);
        self::assertSame(0, $command->execute($input));
        self::assertStringContainsString($targeted, $command->getDisplay());
        self::assertStringNotContainsString('messenger:failed:retry', $command->getDisplay());

        $db->update('itk_request_mail_delivery', ['state' => 'unknown'], ['delivery_key' => $key]);
        $failed = $c->get('messenger.transport.failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);
        self::assertInstanceOf(SenderInterface::class, $failed);
        $envelope = $failed->send(new Envelope($mail));
        $id = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
        try {
            self::assertSame(0, $command->execute($input));
            self::assertStringContainsString($targeted, $command->getDisplay());
            self::assertStringContainsString("messenger:failed:retry --transport='failed' " . \escapeshellarg($id), $command->getDisplay());
        } finally {
            $stored = $failed->find($id);
            if (null !== $stored) {
                $failed->reject($stored);
            }
        }
    }

    #[Test]
    public function explicitSmtpRefusalCanRetryAndAnAmbiguousFailureCannot(): void
    {
        $attempts = 0;
        $control = new class {
            public string $mode = 'refuse';
        };
        $this->transport(static function (MessageEvent $event) use (&$attempts, $control): void {
            ++$attempts;
            if ('refuse' === $control->mode) {
                throw new UnexpectedResponseException('550 fixture rejected', 550);
            }
            if ('unknown' === $control->mode) {
                throw new \RuntimeException('fixture connection lost after SMTP DATA');
            }
            $event->reject();
        });
        $mail = $this->mail();
        try {
            $this->dispatch($mail);
            self::fail('SMTP refusal must reach Messenger');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(MailDeliveryException::class, $e->getPrevious());
            self::assertTrue($e->getPrevious()->safeToRetry);
        }
        $control->mode = 'success';
        $this->dispatch($mail);
        self::assertSame(2, $attempts, 'an explicit refusal permits the next attempt');

        $mail = $this->mail('unknown@example.com');
        $control->mode = 'unknown';
        foreach ([3, 3] as $expectedAttempts) {
            try {
                $this->dispatch($mail);
                self::fail('an unknown outcome must stay in the failed queue for inspection');
            } catch (HandlerFailedException $e) {
                self::assertInstanceOf(MailDeliveryUnknownException::class, $e->getPrevious());
            }
            self::assertSame($expectedAttempts, $attempts);
        }
        $command = new CommandTester((new Application(self::$kernel))->find('itk:requests:mail:resolve'));
        self::assertSame(0, $command->execute(['request' => (string) $this->request->id, 'recipient' => $mail->to, 'outcome' => 'rejected']));
        $control->mode = 'success';
        $this->dispatch($mail);
        self::assertSame(4, $attempts, 'verified rejection explicitly permits a retry');
    }

    /** @return iterable<string, array{string}> */
    public static function compoundStrategies(): iterable
    {
        yield 'failover' => ['failover'];
        yield 'roundrobin' => ['roundrobin'];
    }

    private function compoundBus(string $strategy): MessageBus
    {
        $c = self::getContainer();
        $settings = $c->get(MailSettings::class);
        $settings->save(['enabled' => false, 'fromAddress' => 'site@example.com']);
        $factory = new MailerFactory($settings, $c->get(SettingsService::class), $c->get('event_dispatcher'), $strategy . '(smtp://first.example.test:25 smtp://second.example.test:25)');
        $mailer = new SiteMailer($factory, $c->get(MailStatus::class), $c->get('messenger.default_bus'), new NullLogger());
        $handler = new SendMailHandler($mailer, $c->get(RequestMailWarnings::class), $c->get(RequestMailCheckpoint::class));

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([SendMail::class => [$handler]]))]);
    }

    #[Test]
    #[DataProvider('compoundStrategies')]
    public function compoundTransportCannotRepeatAfterANonTransportListenerFailure(string $strategy): void
    {
        $bus = $this->compoundBus($strategy);
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            ++$attempts;
            // A listener's non-transport failure cannot establish a safe SMTP result.
            if (1 === $attempts) {
                throw new \RuntimeException('fixture listener failed with an unconfirmed outcome');
            }
            $event->reject(); // A second leaf would accept the same letter; never open a network connection.
        });
        $mail = $this->mail();
        foreach ([1, 2] as $envelope) {
            $failure = null;
            try {
                $bus->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
            } catch (HandlerFailedException $error) {
                $failure = $error->getPrevious();
            }
            self::assertSame(1, $attempts, 'an ambiguous acceptance cannot fall through to another SMTP leaf or a duplicate envelope');
            self::assertInstanceOf(MailDeliveryUnknownException::class, $failure, 'envelope ' . $envelope . ' must remain blocked for inspection');
            self::assertSame('unknown', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        }
    }

    #[Test]
    #[DataProvider('compoundStrategies')]
    public function compoundTransportCanFallBackAfterAConfirmedSmtpRefusal(string $strategy): void
    {
        $bus = $this->compoundBus($strategy);
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new UnexpectedResponseException('550 fixture refused before acceptance', 550);
            }
            $event->reject();
        });
        $mail = $this->mail();
        $bus->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
        self::assertSame(2, $attempts, 'a definite rejection permits the next configured SMTP leaf');
        self::assertSame('done', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        $bus->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
        self::assertSame(2, $attempts, 'an accepted fallback is not sent again by a duplicate envelope');
    }

    #[Test]
    #[DataProvider('compoundStrategies')]
    public function compoundTransportCanFallBackAfterAPreDataConnectionFailure(string $strategy): void
    {
        $bus = $this->compoundBus($strategy);
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new TransportException('fixture connect refused before DATA');
            }
            $event->reject();
        });
        $mail = $this->mail();
        $bus->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
        self::assertSame(2, $attempts, 'a pre-DATA transport failure permits the next configured SMTP leaf');
        self::assertSame('done', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
    }

    #[Test]
    public function preDataFailuresRemainRetryableAndARecoveredRecipientCanSend(): void
    {
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new TransportException('fixture connect timeout before DATA');
            }
            $event->reject();
        });
        $mail = $this->mail();
        try {
            $this->dispatch($mail);
            self::fail('the pre-DATA failure must reach Messenger for retry');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(MailDeliveryException::class, $failure->getPrevious());
            self::assertTrue($failure->getPrevious()->safeToRetry);
        }
        self::assertSame('rejected', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        $this->dispatch($mail);
        self::assertSame(2, $attempts);
        self::assertSame('done', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
    }

    #[Test]
    #[DataProvider('compoundStrategies')]
    public function compoundTransportRemainsRetryableWhenEverySmtpLeafConfirmsRejection(string $strategy): void
    {
        $bus = $this->compoundBus($strategy);
        $attempts = 0;
        $control = new class {
            public bool $refuse = true;
        };
        $this->transport(static function (MessageEvent $event) use (&$attempts, $control): void {
            ++$attempts;
            if ($control->refuse) {
                throw new UnexpectedResponseException('550 fixture refused before acceptance', 550);
            }
            $event->reject();
        });
        $mail = $this->mail();
        try {
            $bus->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
            self::fail('all SMTP refusals must reach Messenger');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(MailDeliveryException::class, $failure->getPrevious());
            self::assertTrue($failure->getPrevious()->safeToRetry, 'an aggregate of confirmed rejections must not become an unknown acceptance');
        }
        self::assertSame(2, $attempts);
        self::assertSame('rejected', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        $control->refuse = false;
        $bus->dispatch(new Envelope($mail, [new ReceivedStamp('async')]));
        self::assertSame(3, $attempts, 'a later envelope may retry the rejected operation');
        self::assertSame('done', self::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
    }

    /** @return iterable<string, array{bool}> */
    public static function failuresAfterSmtpAcceptance(): iterable
    {
        yield 'sent-message listener throws SMTP-shaped failure' => [false];
        yield 'restart greeting refuses after successful send' => [true];
    }

    #[Test]
    #[DataProvider('failuresAfterSmtpAcceptance')]
    public function anSmtpShapedFailureAfterAcceptanceCannotBeClassifiedAsSafeToRetry(bool $restart): void
    {
        $responses = ["220 fixture ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n", "354 send data\r\n", "250 accepted fixture\r\n"];
        $responses = [...$responses, ...($restart ? ["221 closing\r\n", "550 restart refused\r\n"] : ["250 reset\r\n", "221 closing\r\n"])];
        $stream = new class($responses) extends AbstractStream {
            public int $accepted = 0;

            /** @param list<string> $responses */
            public function __construct(private array $responses)
            {
            }

            public function initialize(): void
            {
            }

            public function write(string $bytes, bool $debug = true): void
            {
            }

            public function flush(): void
            {
            }

            public function readLine(): string
            {
                $response = \array_shift($this->responses) ?? '';
                if ("250 accepted fixture\r\n" === $response) {
                    ++$this->accepted;
                }

                return $response;
            }

            protected function getReadConnectionDescription(): string
            {
                return 'scripted SMTP fixture';
            }
        };
        $dispatcher = new EventDispatcher();
        if (!$restart) {
            $dispatcher->addListener(SentMessageEvent::class, static function (): never {
                throw new UnexpectedResponseException('550 listener failure after accepted DATA', 550);
            });
        }
        $guard = new RequestMailTransportGuard($dispatcher);
        $smtp = new SmtpTransport($stream, $guard);
        if ($restart) {
            $smtp->setRestartThreshold(1);
        }
        try {
            $guard->protect($smtp)->send((new Email())->from('site@example.com')->to('recipient@example.com')->subject('Fixture')->text('Fixture'));
            self::fail('a post-acceptance failure must stop fallback and require inspection');
        } catch (MailDeliveryUnknownException $failure) {
            self::assertSame(1, $stream->accepted, 'the actual SMTP transport received acceptance before its later failure');
            self::assertFalse($failure->safeToRetry);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function aWorkerCrashAfterSendStartsNeverCausesAnAutomaticResend(): void
    {
        $mail = $this->mail();
        $db = self::getContainer()->get(Connection::class);
        $checkpoint = self::getContainer()->get(RequestMailCheckpoint::class);
        $db->close(); // forked workers must not share a live PostgreSQL socket
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);
        if (0 === $pid) {
            $checkpoint->deliver($mail, static fn () => exit(37));
            exit(38);
        }
        $waited = 0;
        $status = 0;
        try {
            $deadline = \microtime(true) + 10;
            do {
                $waited = \pcntl_waitpid($pid, $status, \WNOHANG);
                if (0 === $waited) {
                    \usleep(10000);
                }
            } while (0 === $waited && \microtime(true) < $deadline);
            self::assertSame($pid, $waited, 'the crashing worker must exit within ten seconds');
        } finally {
            if (0 === $waited) {
                \posix_kill($pid, \SIGKILL);
                \pcntl_waitpid($pid, $status);
            }
        }
        self::assertSame(37, \pcntl_wexitstatus($status));
        self::assertSame('sending', $db->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        $sent = false;
        try {
            $checkpoint->deliver($mail, static function () use (&$sent): void { $sent = true; });
            self::fail('the interrupted SMTP outcome is unknown');
        } catch (MailDeliveryUnknownException) {
            self::assertFalse($sent);
        }
        self::assertSame('unknown', $db->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [RequestMailCheckpoint::key($mail)]));
        $command = new CommandTester((new Application(self::$kernel))->find('itk:requests:mail:resolve'));
        self::assertSame(0, $command->execute(['request' => (string) $this->request->id, 'recipient' => $mail->to, 'outcome' => 'sent']));
        $checkpoint->deliver($mail, static function () use (&$sent): void { $sent = true; });
        self::assertFalse($sent, 'verified acceptance closes the unknown delivery without resending');
        self::assertSame(1, $command->execute(['request' => (string) $this->request->id, 'recipient' => $mail->to, 'outcome' => 'rejected']), 'a completed delivery cannot be rearmed');
    }

    #[Test]
    public function deliveryCheckpointSurvivesRollbackAndLocksOutOtherSessions(): void
    {
        $mail = $this->mail();
        $db = self::getContainer()->get(Connection::class);
        $checkpoint = self::getContainer()->get(RequestMailCheckpoint::class);
        $attempts = 0;
        $db->beginTransaction();
        try {
            $checkpoint->deliver($mail, static function () use ($db, $mail, &$attempts): void {
                ++$attempts;
                self::assertFalse((bool) $db->fetchOne('SELECT pg_try_advisory_lock(?)', [\Itk\Cms\Content\EditLock::key('request-mail', RequestMailCheckpoint::key($mail))]), 'a second PostgreSQL session cannot claim this recipient during SMTP');
            });
        } finally {
            $db->rollBack();
        }
        $checkpoint->deliver($mail, static function () use (&$attempts): void { ++$attempts; });
        self::assertSame(1, $attempts, 'caller rollback cannot erase an accepted send');
    }
}

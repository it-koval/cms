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
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\MailStatus;
use Itk\Cms\Mail\RequestMailCheckpoint;
use Itk\Cms\Mail\RequestMailTransportGuard;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SendMailHandler;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Notification\NotificationInbox;
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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
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
        $db->executeStatement('DELETE FROM itk_notification WHERE dedupe_key = ?', ['mail.failed:' . $this->request->id]);
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
        $handler = new SendMailHandler($mailer, $c->get(NotificationInbox::class), $c->get(RequestMailCheckpoint::class));

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([SendMail::class => [$handler]]))]);
    }

    #[Test]
    #[DataProvider('compoundStrategies')]
    public function compoundTransportCannotRepeatAnAmbiguousAcceptanceInsideOneSend(string $strategy): void
    {
        $bus = $this->compoundBus($strategy);
        $attempts = 0;
        $this->transport(static function (MessageEvent $event) use (&$attempts): void {
            ++$attempts;
            // Simulate accepted DATA followed by a lost response at the real Symfony leaf boundary.
            if (1 === $attempts) {
                throw new TransportException('fixture accepted DATA, then lost the response');
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

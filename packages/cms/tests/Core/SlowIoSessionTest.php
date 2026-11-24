<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Controller\Admin\MailController;
use Itk\Cms\Controller\Admin\SettingsController;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Mail\MailerFactory;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\MailStatus;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Request\Max\MaxSender;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\SessionWork;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SlowIoSessionTest extends KernelTestCase
{
    private function request(array $body = []): Request
    {
        $request = Request::create('/test', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode($body));
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('_security_itk_admin', 'signed-in');
        $request->setSession($session);
        $request->attributes->set('_security_firewall_run', '_security_itk_admin');

        return $request;
    }

    #[Test]
    public function aSecondProcessWithTheSameNativeSessionFinishesDuringSlowIo(): void
    {
        $dir = __DIR__ . '/../App/var/session-profile-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0700, true);
        try {
            $profiles = [];
            foreach (['locked', 'unlocked'] as $mode) {
                $process = new Process([\PHP_BINARY, __DIR__ . '/../App/bin/session-lock-profile.php', $mode, $dir, \bin2hex(\random_bytes(12))], timeout: 5);
                $process->mustRun();
                $profiles[$mode] = \json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            }
            self::assertFalse($profiles['locked']['duringIo']);
            self::assertTrue($profiles['unlocked']['duringIo']);
            self::assertGreaterThan(300, $profiles['locked']['peerMs']);
            self::assertLessThan(300, $profiles['unlocked']['peerMs']);
            \fwrite(\STDERR, \sprintf("\nSame session, 400 ms I/O: peer wait %.1f -> %.1f ms\n", $profiles['locked']['peerMs'], $profiles['unlocked']['peerMs']));
        } finally {
            foreach (\glob($dir . '/*') ?: [] as $file) {
                \unlink($file);
            }
            \rmdir($dir);
        }
    }

    #[Test]
    public function maxDeliveryClosesTheSessionAndKeepsTheSignIn(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('session@example.test', 'Session', 'admin'), 'itk_admin'));
        $request = $this->request(['chatId' => '123']);
        $sender = $this->createMock(MaxSender::class);
        $sender->method('isConnected')->willReturn(true);
        $sender->expects(self::once())->method('send')->willReturnCallback(static function () use ($request): void {
            self::assertFalse($request->getSession()->isStarted(), 'session lock must be released at the transport boundary');
        });
        $max = new MaxNotifier($c->get(Schema::class), $c->get(SettingsService::class), $c->get('logger'), $sender, new EventDispatcher(), true);
        $controller = new SettingsController($c->get(SettingsService::class), $c->get(Schema::class), $c->get(TelegramNotifier::class), $max, $c->get(Access::class), $c->get(FieldAccess::class), $c->get(SiteMailer::class), $c->get(AdminLocale::class), $c->get(SessionWork::class));
        self::assertSame(200, $controller->maxTest($request)->getStatusCode());
        self::assertTrue($request->getSession()->isStarted());
        self::assertTrue($request->getSession()->has('_security_itk_admin'));
    }

    #[Test]
    public function telegramDeliveryAlsoClosesTheSession(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('session@example.test', 'Session', 'admin'), 'itk_admin'));
        $request = $this->request(['chatId' => '-1001234567890']);
        $transport = $this->createMock(\Symfony\Component\Notifier\Transport\TransportInterface::class);
        $transport->method('supports')->willReturnCallback(static fn ($message): bool => $message instanceof \Symfony\Component\Notifier\Message\ChatMessage);
        $transport->method('__toString')->willReturn('telegram://test');
        $transport->expects(self::once())->method('send')->willReturnCallback(static function () use ($request): ?\Symfony\Component\Notifier\Message\SentMessage {
            self::assertFalse($request->getSession()->isStarted());

            return null;
        });
        $telegram = new TelegramNotifier($c->get(Schema::class), $c->get(SettingsService::class), $c->get('logger'), static fn () => $transport, new EventDispatcher(), true);
        $controller = new SettingsController($c->get(SettingsService::class), $c->get(Schema::class), $telegram, $c->get(MaxNotifier::class), $c->get(Access::class), $c->get(FieldAccess::class), $c->get(SiteMailer::class), $c->get(AdminLocale::class), $c->get(SessionWork::class));
        self::assertSame(200, $controller->telegramTest($request)->getStatusCode());
        self::assertTrue($request->getSession()->isStarted());
    }

    #[Test]
    public function mailDeliveryClosesTheSessionAtTheTransportBoundary(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('session@example.test', 'Session', 'admin'), 'itk_admin'));
        $request = $this->request(['to' => 'session@example.test']);
        $dispatcher = new EventDispatcher();
        $called = false;
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event) use ($request, &$called): void {
            $called = true;
            self::assertFalse($request->getSession()->isStarted());
            $event->reject(); // No SMTP connection: this test stops at the real transport boundary.
        });
        $factory = new MailerFactory($c->get(MailSettings::class), $c->get(SettingsService::class), $dispatcher, 'smtp://127.0.0.1:1');
        // The configured site has a sender; explicitly assert this to avoid accidental external I/O.
        $factorySettings = $c->get(SettingsService::class);
        $was = $factorySettings->get('domain');
        $set = $factorySettings->isSet('domain');
        $mailStatusBefore = $factorySettings->storedNow('mailStatus');
        $factorySettings->set('domain', 'session.example.test');
        try {
            $mailer = new SiteMailer($factory, $c->get(MailStatus::class), $c->get('messenger.bus.default'), $c->get('logger'));
            $limiter = $this->createStub(LimiterInterface::class);
            $limiter->method('consume')->willReturn(new RateLimit(1, new \DateTimeImmutable(), true, 1));
            $limit = $this->createStub(RateLimiterFactoryInterface::class);
            $limit->method('create')->willReturn($limiter);
            $controller = new MailController($c->get(MailSettings::class), $factory, $c->get(MailStatus::class), $mailer, $c->get(MailComposer::class), $c->get(Access::class), $limit, $c->get(SessionWork::class));
            self::assertSame(200, $controller->test($request)->getStatusCode());
            self::assertTrue($called);
            self::assertTrue($request->getSession()->isStarted());
        } finally {
            if ($set) {
                $factorySettings->set('domain', $was);
            } else {
                $c->get('doctrine.orm.entity_manager')->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'domain'")->execute();
            }
            if (null !== $mailStatusBefore) {
                $factorySettings->set('mailStatus', $mailStatusBefore);
            } else {
                $c->get('doctrine.orm.entity_manager')->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'mailStatus'")->execute();
            }
        }
    }

    #[Test]
    public function signingOutDuringIoClearsTheTokenEvenWhenIoThrows(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $request = $this->request();
        $tokens = $c->get('security.token_storage');
        $tokens->setToken(new UsernamePasswordToken(new User('session@example.test', 'Session', 'admin'), 'itk_admin'));
        try {
            $c->get(SessionWork::class)->run($request, static function () use ($request): void {
                $request->getSession()->remove('_security_itk_admin');
                throw new \RuntimeException('IO failed');
            });
            self::fail('signed-out requests must not restore the token');
        } catch (AccessDeniedException) {
            self::assertNull($tokens->getToken());
        }
    }
}

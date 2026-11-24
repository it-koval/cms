<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\Entity\User;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Security\PasswordRecovery;
use Itk\Cms\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AccountDeliverySecurityTest extends KernelTestCase
{
    use \Itk\Cms\Tests\Core\Fixture\MailSupport;
    private ?User $user = null;
    /** @var array<string, mixed> */
    private array $previousSettings = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(EntityManagerInterface::class)->getRepository(\Itk\Cms\Entity\Setting::class);
        foreach ([MailSettings::KEY, 'mailStatus'] as $key) {
            $row = $repository->find(['key' => $key, 'locale' => '*']);
            if (null !== $row) {
                $this->previousSettings[$key] = $row->value;
            }
        }
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        if (null !== $this->user) {
            $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.id = :id')->setParameter('id', $this->user->id)->execute();
        }
        $em->clear();
        $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key IN (:keys)')->setParameter('keys', [MailSettings::KEY, 'mailStatus'])->execute();
        foreach ($this->previousSettings as $key => $value) {
            $em->persist(new \Itk\Cms\Entity\Setting($key, '*', $value));
        }
        $em->flush();
        parent::tearDown();
    }

    public function testPasswordResetQueuesAJobBeforeCreatingAToken(): void
    {
        self::getContainer()->get(MailSettings::class)->save(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'clearPassword' => true, 'fromAddress' => 'test@example.com']);
        $this->user = self::getContainer()->get(UserManager::class)->create('test-f1-queue-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        self::getContainer()->get(PasswordRecovery::class)->sendLink($this->user, SendMail::RESET, PasswordRecovery::ADMIN_RESET_TTL, 'https://trusted.example');
        $messages = self::getContainer()->get('messenger.transport.async')->getSent();
        self::assertCount(1, $messages);
        self::assertNotInstanceOf(SendMail::class, $messages[0]->getMessage(), 'Only a job belongs in the durable queue.');
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordReset::class)->count(['user' => $this->user->id]), 'The token is created only when SMTP delivery starts.');
        self::assertStringNotContainsString('/reset-password/', \serialize($messages[0]->getMessage()));
    }

    public function testAnSmtpRetryCreatesANewOneUseLinkAndRevokesTheFailedAttempt(): void
    {
        self::getContainer()->get(MailSettings::class)->save(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'clearPassword' => true, 'fromAddress' => 'test@example.com']);
        $this->user = self::getContainer()->get(UserManager::class)->create('test-f1-retry-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        $recovery = self::getContainer()->get(PasswordRecovery::class);
        $recovery->sendLink($this->user, SendMail::RESET, PasswordRecovery::ADMIN_RESET_TTL, 'https://trusted.example');
        $job = self::getContainer()->get('messenger.transport.async')->getSent()[0]->getMessage();
        self::assertInstanceOf(\Itk\Cms\Security\DeliverPasswordLink::class, $job);
        $handler = self::getContainer()->get(\Itk\Cms\Security\DeliverPasswordLinkHandler::class);
        $failedToken = null;
        $listener = static function (\Symfony\Component\Mailer\Event\MessageEvent $event) use (&$failedToken): void {
            $message = $event->getMessage();
            \assert($message instanceof \Symfony\Component\Mime\Email);
            \preg_match('~/reset-password/([A-Za-z0-9_-]+)~', $message->getTextBody() ?? '', $matches);
            $failedToken = $matches[1] ?? null;
        };
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(\Symfony\Component\Mailer\Event\MessageEvent::class, $listener, 1000);
        try {
            try {
                $handler($job);
                self::fail('The local closed SMTP port must fail.');
            } catch (\Itk\Cms\Mail\MailDeliveryException) {
                self::assertIsString($failedToken);
                self::assertNull($recovery->find($failedToken));
            }
        } finally {
            $dispatcher->removeListener(\Symfony\Component\Mailer\Event\MessageEvent::class, $listener);
        }
        $mail = $this->capturePasswordMail(static fn () => $handler($job));
        self::assertCount(1, $mail);
        $token = \basename($this->linkIn($mail[0]));
        self::assertNotSame($failedToken, $token);
        self::assertNotNull($recovery->find($token));
        $recovery->complete($token, 'new-innocuous-password', 'new-innocuous-password');
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull($recovery->find($token), 'The successful retry link works once.');
    }

    public function testAQueuedJobCannotSurviveAnAddressChangeEvenWithACachedUser(): void
    {
        self::getContainer()->get(MailSettings::class)->save(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'clearPassword' => true, 'fromAddress' => 'test@example.com']);
        $this->user = self::getContainer()->get(UserManager::class)->create('test-f1-stale-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        self::getContainer()->get(PasswordRecovery::class)->sendLink($this->user, SendMail::INVITE, PasswordRecovery::INVITE_TTL, 'https://trusted.example');
        $job = self::getContainer()->get('messenger.transport.async')->getSent()[0]->getMessage();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE itk_user SET email = ? WHERE id = ?', ['test-f1-changed-' . \bin2hex(\random_bytes(5)) . '@example.com', $this->user->id]);
        self::assertCount(0, $this->capturePasswordMail(static fn () => self::getContainer()->get(\Itk\Cms\Security\DeliverPasswordLinkHandler::class)($job)));
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordReset::class)->count(['user' => $this->user->id]));
    }

    public function testLegacyQueuedPasswordRequestsCannotUseTheirUntrustedBase(): void
    {
        self::getContainer()->get(MailSettings::class)->save(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'clearPassword' => true, 'fromAddress' => 'test@example.com']);
        $this->user = self::getContainer()->get(UserManager::class)->create('test-f1-legacy-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        $job = new \Itk\Cms\Security\RequestPasswordLink($this->user->email, 'https://old-editable-domain.example');
        $serialized = \serialize($job);
        $serialized = \str_replace([':3:{', 's:7:"trusted";b:0;'], [':2:{', ''], $serialized);
        $job = \unserialize($serialized, ['allowed_classes' => [\Itk\Cms\Security\RequestPasswordLink::class]]);
        self::assertInstanceOf(\Itk\Cms\Security\RequestPasswordLink::class, $job);
        self::assertFalse($job->trusted, 'Actual old serialized payloads have no trust marker.');
        $mail = $this->capturePasswordMail(static fn () => self::getContainer()->get(\Itk\Cms\Security\RequestPasswordLinkHandler::class)($job));
        self::assertCount(0, $mail, 'Old request jobs never create a fresh token for a formerly editable domain.');
    }

    public function testLegacyRenderedPasswordLettersAreNotDelivered(): void
    {
        self::getContainer()->get(MailSettings::class)->save(['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'clearPassword' => true, 'fromAddress' => 'test@example.com']);
        foreach ([SendMail::RESET, SendMail::INVITE] as $kind) {
            $mail = new SendMail('fixture@example.com', 'Legacy fixture', 'Old password letter', '<p>Old password letter</p>', $kind);
            self::assertCount(0, $this->capturePasswordMail(static fn () => self::getContainer()->get(\Itk\Cms\Mail\SendMailHandler::class)($mail), $kind));
        }
    }

    public function testPasswordRequiresTlsAtTheSettingsEntryPoint(): void
    {
        $this->expectException(ContentException::class);
        self::getContainer()->get(MailSettings::class)->save(['host' => '127.0.0.1', 'encryption' => 'none', 'password' => 'innocuous-test-password']);
    }

    public function testPasswordLinksHaveNoDomainOrDebugFallback(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('http://localhost'));
        $urls = new PublicUrl($requests, self::getContainer()->get(SettingsService::class), null, true);
        self::assertNull($urls->trustedBase());
        self::assertSame('https://trusted.example', (new PublicUrl($requests, self::getContainer()->get(SettingsService::class), 'https://trusted.example/'))->trustedBase());
        $configured = new PublicUrl($requests, self::getContainer()->get(SettingsService::class), 'HTTPS://Trusted.Example:08443/');
        self::assertSame('https://trusted.example:8443', $configured->trustedBase());
        self::assertSame('https://trusted.example:8443', $configured->base(), 'An accepted configured origin keeps its explicit port for ordinary links, written as a normalized origin.');
    }

    public function testTrustedHostsDoNotAuthorizeACompletePasswordLinkOrigin(): void
    {
        $previous = Request::getTrustedHosts();
        Request::setTrustedHosts(['^trusted\\.example$']);
        try {
            $requests = new RequestStack();
            $requests->push(Request::create('http://trusted.example:8099'));
            $urls = new PublicUrl($requests, self::getContainer()->get(SettingsService::class));
            self::assertNull($urls->trustedBase(), 'A trusted hostname does not establish the scheme and port for account links.');
            self::assertSame('http://trusted.example:8099', $urls->base(), 'Ordinary site links retain the trusted-host fallback.');
            self::assertSame('warn', (new \Itk\Cms\Health\TrustedPublicUrlHealthCheck($urls))->check()->status);
        } finally {
            Request::setTrustedHosts(\array_map(static fn (string $pattern): string => \substr($pattern, 1, -2), $previous));
        }
    }

    public function testCachedResetLinkCannotBeUsedAfterItWasConsumedElsewhere(): void
    {
        $this->user = self::getContainer()->get(UserManager::class)->create('test-f1-used-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        $token = \bin2hex(\random_bytes(24));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $link = new PasswordReset($this->user, PasswordRecovery::hash($token), PasswordReset::RESET, new \DateTimeImmutable('+1 hour'));
        $em->persist($link);
        $em->flush();
        $recovery = self::getContainer()->get(PasswordRecovery::class);
        self::assertNotNull($recovery->find($token));
        $em->getConnection()->executeStatement('UPDATE itk_password_reset SET used_at = CURRENT_TIMESTAMP WHERE id = ?', [$link->id]);
        try {
            $recovery->complete($token, 'new-innocuous-password', 'new-innocuous-password');
            self::fail('A spent link must be rejected after acquiring the account lock.');
        } catch (ContentException $error) {
            self::assertSame(410, $error->status());
        }
    }

    public function testAnEnvironmentSmtpPasswordWithoutRequiredTlsWarnsWithoutDisablingTheRoute(): void
    {
        $settings = self::getContainer()->get(MailSettings::class);
        $settings->save(['enabled' => false, 'fromAddress' => 'test@example.com']);
        foreach (['smtp://fixture:fixture-password@localhost:1025', 'smtp://fixture:fixture-password@localhost:1025?auto_tls=false', 'failover(smtps://fixture:fixture-password@localhost:1025 smtp://fixture:fixture-password@localhost:1025)'] as $dsn) {
            $factory = new \Itk\Cms\Mail\MailerFactory($settings, self::getContainer()->get(SettingsService::class), self::getContainer()->get('event_dispatcher'), $dsn);
            self::assertNotNull($factory->route(), 'The existing environment route is preserved.');
            $health = new \Itk\Cms\Mail\MailHealthCheck($factory, self::getContainer()->get(\Itk\Cms\Mail\MailStatus::class), self::getContainer()->get(SettingsService::class));
            self::assertSame('warn', $health->check()->status);
            self::assertStringNotContainsString('fixture-password', $health->check()->message);
        }
        foreach (['smtp://fixture:fixture-password@localhost:1025?require_tls=1', 'smtps://fixture:fixture-password@localhost:1025', 'smtp://localhost:1025'] as $dsn) {
            $factory = new \Itk\Cms\Mail\MailerFactory($settings, self::getContainer()->get(SettingsService::class), self::getContainer()->get('event_dispatcher'), $dsn);
            self::assertFalse($factory->hasUnsafeEnvCredentials());
        }
    }

    public function testShortSmtpPasswordsNeverReachFailureLogsOrHealthState(): void
    {
        foreach (['q', 'qz', 'qzx'] as $password) {
            $settings = self::getContainer()->get(MailSettings::class);
            $settings->save(['enabled' => true, 'host' => 'localhost', 'port' => 1, 'encryption' => 'starttls', 'username' => 'fixture', 'password' => $password, 'fromAddress' => 'test@example.com']);
            $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
            $dispatcher->addListener(\Symfony\Component\Mailer\Event\MessageEvent::class, static function () use ($password): never {
                // Refuse before a transport opens a connection: a synthetic SMTP error containing the known password.
                throw new \RuntimeException('SMTP fixture rejected ' . $password);
            });
            $factory = new \Itk\Cms\Mail\MailerFactory($settings, self::getContainer()->get(SettingsService::class), $dispatcher);
            $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with(self::anything(), ['kind' => SendMail::TEST, 'error' => 'SMTP *** rejected ***']);
            $status = self::getContainer()->get(\Itk\Cms\Mail\MailStatus::class);
            $mailer = new \Itk\Cms\Mail\SiteMailer($factory, $status, $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class), $logger);
            try {
                $mailer->deliver(new SendMail('fixture@example.com', 'Fixture', 'Fixture', '<p>Fixture</p>', SendMail::TEST));
                self::fail('The synthetic transport boundary must fail.');
            } catch (\Itk\Cms\Mail\MailDeliveryException $error) {
                self::assertStringNotContainsString($password, $error->getMessage());
                self::assertStringNotContainsString($password, $status->get()['error']);
            }
        }
    }

    public function testCompositeEnvironmentDsnsScrubEveryPasswordWithoutRewritingTheTransport(): void
    {
        $settings = self::getContainer()->get(MailSettings::class);
        $settings->save(['enabled' => false, 'fromAddress' => 'test@example.com']);
        foreach (['failover', 'roundrobin'] as $strategy) {
            $dsn = $strategy . '(smtp://first:q@localhost:1025?require_tls=1 smtps://second:qz@localhost:1026 smtp://third:q%7Ax@localhost:1027)';
            $factory = new \Itk\Cms\Mail\MailerFactory($settings, self::getContainer()->get(SettingsService::class), self::getContainer()->get('event_dispatcher'), $dsn);
            $route = $factory->route();
            self::assertNotNull($route);
            self::assertSame($dsn, $route->dsn);
            foreach (['q', 'qz', 'qzx'] as $password) {
                self::assertContains($password, $route->secrets);
                self::assertSame('SMTP rejected ***', \Itk\Cms\Mail\DsnBuilder::clean('SMTP rejected ' . $password, $route->secrets, $route->usernames));
            }
            self::assertTrue($factory->hasUnsafeEnvCredentials());
        }
    }

    public function testUpgradeBurnsExistingLinksWithoutRemovingTheirAuditRows(): void
    {
        $this->user = self::getContainer()->get(UserManager::class)->create('test-f1-upgrade-' . \bin2hex(\random_bytes(5)) . '@example.com', 'Fixture', 'editor', 'innocuous-test-password');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $token = \bin2hex(\random_bytes(24));
        $link = new PasswordReset($this->user, PasswordRecovery::hash($token), PasswordReset::INVITE, new \DateTimeImmutable('+1 day'));
        $em->persist($link);
        $em->flush();
        $db = $em->getConnection();
        $db->beginTransaction();
        try {
            $migration = new \Itk\Cms\Migrations\Version20261124110000($db, new \Psr\Log\NullLogger());
            $migration->up($db->createSchemaManager()->introspectSchema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            $em->refresh($link);
            self::assertNotNull($link->usedAt);
            self::assertFalse($link->isUsable());
        } finally {
            $db->rollBack();
            $em->clear();
        }
    }

    public function testHealthReportAndDoctorWarnWhenAccountLinkOriginIsMissing(): void
    {
        $checks = self::getContainer()->get(\Itk\Cms\Health\HealthService::class)->report()['checks'];
        $name = \Itk\Cms\I18n\I18n::t('ops.doctor_name_public_url');
        $matching = \array_values(\array_filter($checks, static fn (array $check): bool => $name === $check['name']));
        self::assertCount(1, $matching, 'The automatic health tag exposes the account-link diagnostic.');
        self::assertSame('warn', $matching[0]['status']);
        self::assertStringContainsString('itk_cms.public_url', $matching[0]['message']);
        $doctor = self::getContainer()->get(\Itk\Cms\Health\Doctor::class)->run();
        $matching = \array_values(\array_filter($doctor, static fn (array $check): bool => $name === $check['name']));
        self::assertCount(1, $matching, 'Doctor must not duplicate the tagged check.');
        self::assertSame('warn', $matching[0]['status']);
    }
}

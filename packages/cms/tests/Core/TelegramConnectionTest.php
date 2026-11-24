<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Health\TelegramHealthCheck;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Request\TelegramConnection;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\TwoFactor\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Transport\NullTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Component\Process\Process;

final class TelegramConnectionTest extends TestCase
{
    private ?Setting $row = null;
    private ?Process $process = null;

    protected function tearDown(): void
    {
        try {
            if (null !== $this->process && $this->process->isRunning()) {
                $this->process->stop(1);
            }
        } finally {
            $this->process = null;
            parent::tearDown();
        }
    }

    private function bots(): BotSettings
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('find')->willReturnCallback(fn (): ?Setting => $this->row);

        return new BotSettings($this->createStub(EntityManagerInterface::class), $repository, new SecretBox('telegram-test-secret'));
    }

    private function token(?string $token): void
    {
        $this->row = null === $token ? null : new Setting(BotSettings::TELEGRAM_KEY, Setting::ALL, ['token' => (new SecretBox('telegram-test-secret'))->seal($token)]);
    }

    private function schema(): Schema
    {
        return new Schema('ru', [], [], [], ['top' => [], 'bottom' => []], [], [], [], [], []);
    }

    /** The notifier only reads these preloaded site settings; no database is needed for the actual HTTP sending path. */
    private function siteSettings(Schema $schema): SettingsService
    {
        $class = new \ReflectionClass(SettingsService::class);
        $settings = $class->newInstanceWithoutConstructor();
        foreach (['schema' => $schema, 'mainLocale' => 'ru', 'fallbackChatIds' => '', 'maxFallbackChatIds' => '', 'loaded' => [Setting::ALL => ['itk:tgOn' => true, 'itk:tgRoutes' => ['*' => '-10012345']]]] as $key => $value) {
            $class->getProperty($key)->setValue($settings, $value);
        }

        return $settings;
    }

    #[Test]
    public function rawAdminTokensAndUrlTokensAreRemovedFromErrors(): void
    {
        $token = '123456:admin-token';
        $message = 'Token ' . $token . ' refused at https://api.telegram.org/bot' . $token . '/sendMessage; encoded ' . \rawurlencode($token);

        $clean = TelegramConnection::scrub($message, [$token]);

        self::assertStringNotContainsString($token, $clean);
        self::assertStringNotContainsString(\rawurlencode($token), $clean);
        self::assertStringContainsString('/sendMessage', $clean);
    }

    #[Test]
    public function aServerTransportReportsItsSourceWithoutSending(): void
    {
        $transport = new TelegramTransport('123456:server-token');
        $connection = new TelegramConnection(static fn (): TelegramTransport => $transport, new NullLogger());

        self::assertSame('server', $connection->source());
        self::assertSame($transport, $connection->transport());
        self::assertNull($connection->problem());
    }

    #[Test]
    public function anAdminTokenSendsTheRequestAndTestMessageWithoutServerChatter(): void
    {
        $this->token('123456:admin-token');
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.telegram.org/bot123456:admin-token/sendMessage', $url);
            $body = \json_decode($options['body'], true);
            self::assertSame('-10012345', $body['chat_id']);
            self::assertSame('HTML', $body['parse_mode']);

            return new MockResponse('{"ok":true,"result":{"message_id":1}}');
        });
        $connection = new TelegramConnection(null, new NullLogger(), $this->bots(), $http);
        $schema = $this->schema();
        $site = $this->siteSettings($schema);
        $notifier = new TelegramNotifier($schema, $site, new NullLogger(), $connection, new EventDispatcher(), true);

        self::assertTrue($notifier->isConnected());
        self::assertSame('admin', $notifier->source());
        $request = new Request('call');
        self::assertTrue($notifier->notify($request));
        self::assertSame(Request::DELIVERY_SENT, $request->delivery);
        $notifier->sendTo('-10012345', 'Test');
        self::assertSame(2, $http->getRequestsCount());
        $health = new TelegramHealthCheck($notifier, $site, $schema);
        self::assertSame('ok', $health->check()->status);
        self::assertSame(I18n::t('ops.health_telegram_admin'), $health->check()->message);
    }

    #[Test]
    public function adminTokenWinsAndChangesTakeEffectWithoutRebuildingTheConnection(): void
    {
        $this->token('123456:first-token');
        $first = new MockResponse('{"ok":true,"result":{"message_id":1}}');
        $second = new MockResponse('{"ok":true,"result":{"message_id":2}}');
        $http = new MockHttpClient([$first, $second]);
        $server = new TelegramTransport('123456:server-token');
        $calls = 0;
        $connection = new TelegramConnection(static function () use ($server, &$calls): TelegramTransport {
            ++$calls;

            return $server;
        }, new NullLogger(), $this->bots(), $http);

        $connection->transport()->send((new ChatMessage('First'))->options((new TelegramOptions())->chatId('-10012345')));
        self::assertSame('https://api.telegram.org/bot123456:first-token/sendMessage', $first->getRequestUrl());
        self::assertSame(0, $calls);
        $this->token('123456:second-token');
        $connection->transport()->send((new ChatMessage('Second'))->options((new TelegramOptions())->chatId('-10012345')));
        self::assertSame('https://api.telegram.org/bot123456:second-token/sendMessage', $second->getRequestUrl());
        $this->token(null);
        self::assertSame('server', $connection->source());
        self::assertSame($server, $connection->transport());
        self::assertSame(1, $calls);
    }

    #[Test]
    public function unreadableAdminTokensFallBackToServerButHealthWarns(): void
    {
        $this->row = new Setting(BotSettings::TELEGRAM_KEY, Setting::ALL, ['token' => (new SecretBox('old-key'))->seal('123456:old-token')]);
        $server = new TelegramTransport('123456:server-token');
        $connection = new TelegramConnection(static fn (): TelegramTransport => $server, new NullLogger(), $this->bots());
        $schema = $this->schema();
        $site = $this->siteSettings($schema);
        $notifier = new TelegramNotifier($schema, $site, new NullLogger(), $connection, new EventDispatcher(), true);

        self::assertSame('server', $connection->source());
        self::assertSame($server, $connection->transport());
        self::assertSame(I18n::t('http.bot_token_unreadable', ['channel' => 'Telegram']), $connection->problem());
        $health = (new TelegramHealthCheck($notifier, $site, $schema))->check();
        self::assertSame('warn', $health->status);
        self::assertStringContainsString(I18n::t('ops.health_telegram_server'), $health->message);
    }

    #[Test]
    public function aConfiguredButSwitchedOffBotKeepsItsTokenSourceInHealth(): void
    {
        $this->token('123456:admin-token');
        $connection = new TelegramConnection(null, new NullLogger(), $this->bots());
        $schema = $this->schema();
        $site = $this->siteSettings($schema);
        $notifier = new TelegramNotifier($schema, $site, new NullLogger(), $connection, new EventDispatcher(), false);

        $health = (new TelegramHealthCheck($notifier, $site, $schema))->check();
        self::assertSame('off', $health->status);
        self::assertStringContainsString(I18n::t('ops.health_telegram_off_config'), $health->message);
        self::assertStringContainsString(I18n::t('ops.health_telegram_admin'), $health->message);
    }

    #[Test]
    public function anUnconfiguredBotExplainsSetupButKeepsHealthNeutral(): void
    {
        $connection = new TelegramConnection(null, new NullLogger(), $this->bots());
        $schema = $this->schema();
        $site = $this->siteSettings($schema);
        $notifier = new TelegramNotifier($schema, $site, new NullLogger(), $connection, new EventDispatcher(), true);

        self::assertSame(I18n::t('http.telegram_problem_token'), $notifier->problem());
        self::assertNull($notifier->problem(false));
        $health = (new TelegramHealthCheck($notifier, $site, $schema))->check();
        self::assertSame('off', $health->status);
        self::assertSame(I18n::t('ops.health_telegram_off'), $health->message);
    }

    #[Test]
    public function adminFailuresAreRedactedBeforePersistingLoggingAndReturningThem(): void
    {
        $token = '123456:admin-secret';
        $this->token($token);
        $logger = new class extends AbstractLogger {
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = \json_encode([$message, $context]);
            }
        };
        $http = new MockHttpClient(static fn (): never => throw new \RuntimeException('Refused ' . $token . ' at https://api.telegram.org/bot' . $token . '/sendMessage'));
        $connection = new TelegramConnection(null, $logger, $this->bots(), $http);
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), $logger, $connection, new EventDispatcher(), true);
        $request = new Request('call');

        self::assertFalse($notifier->notify($request));
        self::assertSame(Request::DELIVERY_FAILED, $request->delivery);
        self::assertStringNotContainsString($token, (string) $request->deliveryError);
        self::assertStringContainsString('Refused', (string) $request->deliveryError);
        try {
            $notifier->sendTo('-10012345', 'test');
            self::fail('The test send must fail');
        } catch (ContentException $e) {
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringContainsString('Refused', $e->getMessage());
        }
        self::assertCount(2, $logger->errors);
        self::assertStringNotContainsString($token, \implode('\n', $logger->errors));
    }

    #[Test]
    public function resetRebuildsTheOptionalServerTransportAndNullNeverCountsAsConnected(): void
    {
        $server = new NullTransport();
        $connection = new TelegramConnection(static function () use (&$server): TransportInterface { return $server; }, new NullLogger());
        self::assertFalse($connection->isConnected());
        self::assertNull($connection->source());
        $server = new TelegramTransport('123456:server-token');
        self::assertFalse($connection->isConnected());
        $connection->reset();
        self::assertTrue($connection->isConnected());
        self::assertSame('server', $connection->source());
    }

    #[Test]
    public function missingOptionalNotifierPackagesKeepTelegramDisconnected(): void
    {
        $script = <<<'PHP'
            $loader = require $argv[1];
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (str_starts_with($class, 'Symfony\\Component\\Notifier\\')) {
                    return;
                }
                $loader->loadClass($class);
            });
            $connection = new Itk\Cms\Request\TelegramConnection(static fn (): never => throw new RuntimeException('Chatter must not be built'), new Psr\Log\NullLogger());
            if ($connection->isConnected() || null !== $connection->source() || null !== $connection->problem(false) || null === $connection->problem()) {
                exit(2);
            }
            echo 'disconnected';
            PHP;
        $process = $this->process = new Process([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 2) . '/vendor/autoload.php'], timeout: 25);
        $process->mustRun();

        self::assertSame('disconnected', $process->getOutput());
    }

    public static function optionalPackages(): iterable
    {
        yield 'notifier' => ['Symfony\\Component\\Notifier\\'];
        yield 'telegram bridge' => ['Symfony\\Component\\Notifier\\Bridge\\Telegram\\'];
        yield 'http client' => ['Symfony\\Component\\HttpClient\\'];
    }

    #[Test]
    #[DataProvider('optionalPackages')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anAdminTokenWithoutAnOptionalSendingPackageIsNotReady(string $namespace): void
    {
        foreach (\spl_autoload_functions() as $autoload) {
            if (\is_array($autoload) && $autoload[0] instanceof \Composer\Autoload\ClassLoader) {
                $loader = $autoload[0];
                $loader->unregister();
                \spl_autoload_register(static function (string $class) use ($loader, $namespace): void {
                    if (!\str_starts_with($class, $namespace)) {
                        $loader->loadClass($class);
                    }
                });
            }
        }
        $this->token('123456:admin-token');
        $connection = new TelegramConnection(null, new NullLogger(), $this->bots());

        self::assertSame('admin', $connection->source());
        self::assertFalse($connection->isConnected());
        self::assertSame(I18n::t('http.telegram_packages_missing'), $connection->problem());
    }
}

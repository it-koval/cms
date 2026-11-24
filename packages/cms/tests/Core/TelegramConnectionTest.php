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
        return new Schema('ru', [], [], ['top' => [], 'bottom' => []], [], [], [], [], []);
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

    /** @param array<string, int|float> $info */
    private function networkFailure(array $info): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        // MockResponse normalizes an HTTP status of 0 to 200 when reading headers. Fail before headers instead.
        $response = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getInfo')->willReturnCallback(static fn (?string $type = null): mixed => null === $type ? $info + ['http_code' => 0] : ($info[$type] ?? null));
        $response->method('getStatusCode')->willThrowException(new \Symfony\Component\HttpClient\Exception\TransportException('network failure'));

        return $response;
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
        self::assertSame((string) $transport, (string) $connection->transport());
        self::assertNull($connection->problem());
    }

    #[Test]
    #[DataProvider('telegramSources')]
    public function neitherTokenSourceRepeatsAPostThroughDecoratedRetryClients(bool $admin): void
    {
        $wire = new MockHttpClient([
            new MockResponse('{"description":"unavailable","error_code":503}', ['http_code' => 503]),
            new MockResponse('{"description":"unavailable","error_code":503}', ['http_code' => 503]),
            new MockResponse('{"ok":true,"result":{"message_id":1}}'),
        ]);
        $retrying = new \Symfony\Component\HttpClient\RetryableHttpClient($wire, new \Symfony\Component\HttpClient\Retry\GenericRetryStrategy([503], 0));
        $decorated = new \Symfony\Component\HttpClient\TraceableHttpClient(\Symfony\Component\HttpClient\ScopingHttpClient::forBaseUri($retrying, 'https://api.telegram.org', ['max_retries' => 4]));
        $server = new \Symfony\Component\Notifier\Transport\Transports(['telegram' => new TelegramTransport('123456:server-token', client: $decorated)]);
        if ($admin) {
            $this->token('123456:admin-token');
        }
        $connection = new TelegramConnection(static fn (): TransportInterface => $server, new NullLogger(), $this->bots(), $decorated);
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), new NullLogger(), $connection, new EventDispatcher(), true);

        self::assertTrue($notifier->attempt(new Request('call'))->uncertain);
        self::assertSame(1, $wire->getRequestsCount(), 'one provider attempt must issue only one POST');
        // The application transport still has its own retry policy.
        $server->send((new ChatMessage('site notification'))->options((new TelegramOptions())->chatId('1')));
        self::assertSame(3, $wire->getRequestsCount());
    }

    /** @return iterable<string, array{bool}> */
    public static function telegramSources(): iterable
    {
        yield 'admin token' => [true];
        yield 'server DSN transport collection' => [false];
    }

    #[Test]
    #[DataProvider('telegramSources')]
    public function deliveryDisablesPostPreservingRedirectsForEitherTokenSource(bool $admin): void
    {
        foreach ([307, 308] as $status) {
            $wire = new MockHttpClient(static function (string $method, string $url, array $options) use ($status): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame(0, $options['max_redirects']);

                return new MockResponse('{"description":"redirected","error_code":' . $status . '}', ['http_code' => $status, 'response_headers' => ['location: https://api.telegram.org/redirected']]);
            });
            $scoped = \Symfony\Component\HttpClient\ScopingHttpClient::forBaseUri($wire, 'https://api.telegram.org', ['max_redirects' => 5]);
            if ($admin) {
                $this->token('123456:admin-token');
            }
            $connection = new TelegramConnection(static fn (): TransportInterface => new TelegramTransport('123456:server-token', client: $scoped), new NullLogger(), $this->bots(), $scoped);
            $schema = $this->schema();
            $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), new NullLogger(), $connection, new EventDispatcher(), true);

            self::assertTrue($notifier->attempt(new Request('call'))->uncertain);
            self::assertSame(1, $wire->getRequestsCount());
        }
    }

    /** @param array<string, int|float> $info */
    #[Test]
    #[DataProvider('networkProgress')]
    public function networkFailureOnlyRetriesWhenTheProviderCouldNotHaveReceivedThePost(array $info, bool $uncertain): void
    {
        $response = $this->networkFailure($info);
        $wire = new MockHttpClient($response);
        $connection = new TelegramConnection(static fn (): TransportInterface => new TelegramTransport('123456:server-token', client: $wire), new NullLogger());
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), new NullLogger(), $connection, new EventDispatcher(), true);
        $saved = [];
        $progress = new \Itk\Cms\Request\DeliveryProgress([], static function (string $key, ?array $part) use (&$saved): void { $saved[] = $part; });

        $outcome = $notifier->attempt(new Request('call'), progress: $progress);

        self::assertSame(Request::DELIVERY_FAILED, $outcome->state);
        self::assertSame($uncertain, $outcome->uncertain);
        self::assertSame($uncertain ? 'sending' : null, $saved[\array_key_last($saved)]['state'] ?? null);
    }

    /** @return iterable<string, array{array<string, int|float>, bool}> */
    public static function networkProgress(): iterable
    {
        yield 'DNS/connect/TLS never reached transfer' => [['pretransfer_time' => 0.0, 'size_upload' => 0.0, 'request_size' => 0], false];
        yield 'connect timeout before transfer' => [['pretransfer_time' => 0.0, 'size_upload' => 0.0], false];
        yield 'response lost after upload' => [['pretransfer_time' => 0.01, 'size_upload' => 123.0, 'request_size' => 300], true];
        yield 'headers left the host' => [['pretransfer_time' => 0.0, 'size_upload' => 0.0, 'request_size' => 120], true];
        yield 'no progress evidence' => [[], true];
    }

    #[Test]
    #[DataProvider('telegramFailovers')]
    public function serverCompositionsOnlyMoveToAnotherBotAfterDefiniteRefusal(string $composition, int $status, bool $secondFails, bool $uncertain, int $expectedSecondCalls): void
    {
        $first = new MockHttpClient(0 === $status ? $this->networkFailure(['pretransfer_time' => 0.0, 'size_upload' => 0.0, 'request_size' => 0]) : new MockResponse('{"description":"first failure","error_code":' . $status . '}', ['http_code' => $status]));
        $second = new MockHttpClient(new MockResponse($secondFails ? '{"description":"second failure","error_code":400}' : '{"ok":true,"result":{"message_id":2}}', ['http_code' => $secondFails ? 400 : 200]));
        $bots = [new TelegramTransport('123456:first', client: $first), new TelegramTransport('123456:second', client: $second)];
        $server = 'failover' === $composition
            ? new \Symfony\Component\Notifier\Transport\FailoverTransport($bots)
            : new class($bots) extends \Symfony\Component\Notifier\Transport\RoundRobinTransport {
                protected function getInitialCursor(): int
                {
                    return 0;
                }
            };
        $connection = new TelegramConnection(static fn (): TransportInterface => $server, new NullLogger());
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), new NullLogger(), $connection, new EventDispatcher(), true);

        $outcome = $notifier->attempt(new Request('call'));

        self::assertSame($uncertain, $outcome->uncertain);
        self::assertSame($uncertain || $secondFails ? Request::DELIVERY_FAILED : Request::DELIVERY_SENT, $outcome->state);
        self::assertSame(1, $first->getRequestsCount());
        self::assertSame($expectedSecondCalls, $second->getRequestsCount());
    }

    /** @return iterable<string, array{string, int, bool, bool, int}> */
    public static function telegramFailovers(): iterable
    {
        foreach (['failover', 'roundrobin'] as $composition) {
            foreach ([0, 400, 429] as $status) {
                yield $composition . ' definite ' . $status => [$composition, $status, false, false, 1];
            }
            foreach ([408, 500, 503] as $status) {
                yield $composition . ' uncertain ' . $status => [$composition, $status, false, true, 0];
            }
            yield $composition . ' all refused' => [$composition, 400, true, false, 1];
        }
    }

    #[Test]
    public function exhaustedRoundRobinIsAConfirmedRefusalRatherThanAnUnknownFailure(): void
    {
        $bots = [];
        foreach ([400, 429] as $status) {
            $bots[] = new TelegramTransport('123456:refused', client: new MockHttpClient(new MockResponse('{"description":"refused","error_code":' . $status . '}', ['http_code' => $status])));
        }
        $transport = \Itk\Cms\Request\TelegramTransportGuard::transport(new \Symfony\Component\Notifier\Transport\RoundRobinTransport($bots));
        $this->expectException(\Itk\Cms\Request\DeliveryRefusedException::class);

        $transport->send((new ChatMessage('test'))->options((new TelegramOptions())->chatId('1')));
    }

    #[Test]
    #[DataProvider('postAcceptanceFailures')]
    public function acceptedPostsStayAcceptedWhenPostSendProcessingFails(bool $listenerHttpFailure): void
    {
        $wire = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true,"result":{"message_id":1}}'));
        $otherWire = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"description":"rate limited","error_code":429}', ['http_code' => 429]));
        $other = new TelegramTransport('123456:other-secret', client: $otherWire);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(\Symfony\Component\Notifier\Event\SentMessageEvent::class, static function () use ($other, $listenerHttpFailure): void {
            if (!$listenerHttpFailure) {
                throw new \RuntimeException('listener failed at https://api.telegram.org/bot123456:other-secret/sendMessage using 123456:first-secret and 123456%3Afirst-secret');
            }
            $other->send((new ChatMessage('another notification'))->options((new TelegramOptions())->chatId('2')));
        });
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) \json_encode([$message, $context]);
            }
        };
        $fallback = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true,"result":{"message_id":2}}'));
        $first = new TelegramTransport('123456:first-secret', client: $wire, dispatcher: $dispatcher);
        $server = new \Symfony\Component\Notifier\Transport\FailoverTransport([$first, new TelegramTransport('123456:second-secret', client: $fallback)]);
        $connection = new TelegramConnection(static fn (): TransportInterface => $server, $logger, $this->bots(), $wire);
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), $logger, $connection, new EventDispatcher(), true);
        $snapshot = [];
        $save = static function (string $key, ?array $part) use (&$snapshot): void {
            $snapshot[$key] = $part;
        };
        $progress = new \Itk\Cms\Request\DeliveryProgress([], $save);
        $request = new Request('call');

        self::assertSame(Request::DELIVERY_SENT, $notifier->attempt($request, progress: $progress)->state);
        self::assertSame('sent', \array_values($snapshot)[0]['state']);
        self::assertSame(Request::DELIVERY_SENT, $notifier->attempt($request, progress: new \Itk\Cms\Request\DeliveryProgress($snapshot, $save))->state);
        self::assertSame(1, $wire->getRequestsCount(), 'an accepted part must not be sent by a later worker');
        self::assertSame(0, $fallback->getRequestsCount(), 'a listener failure must not activate failover');
        self::assertSame($listenerHttpFailure ? 1 : 0, $otherWire->getRequestsCount());
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString($listenerHttpFailure ? 'rate limited' : 'listener failed', $logger->warnings[0]);
        self::assertStringNotContainsString('other-secret', $logger->warnings[0]);
        self::assertStringNotContainsString('first-secret', $logger->warnings[0]);
    }

    /** @return iterable<string, array{bool}> */
    public static function postAcceptanceFailures(): iterable
    {
        yield 'server listener separate Telegram 429' => [true];
        yield 'server listener arbitrary exception' => [false];
    }

    /** @return iterable<string, array{bool, int, string, bool, bool}> */
    public static function originalTelegramResponses(): iterable
    {
        foreach ([true, false] as $admin) {
            foreach ([
                'confirmed API acknowledgement' => [200, '{"ok":true,"result":{"message_id":1}}', true, false],
                'malformed 200' => [200, '<html>gateway</html>', false, false],
                'empty 200' => [200, '', false, false],
                'missing ok' => [200, '{"result":{"message_id":1}}', false, false],
                'false ok' => [200, '{"ok":false,"result":{"message_id":1}}', false, false],
                'string ok' => [200, '{"ok":"true","result":{"message_id":1}}', false, false],
                'missing message id' => [200, '{"ok":true,"result":{}}', false, false],
                'empty success' => [204, '', false, false],
                'response lost after transfer' => [0, '', false, false],
                'HTML refusal' => [400, '<html>refused</html>', false, true],
                'HTML rate limit' => [429, '<html>rate limit</html>', false, true],
                'HTML request timeout' => [408, '<html>timeout</html>', false, false],
                'HTML provider error' => [500, '<html>outage</html>', false, false],
                'provider error' => [503, '{"description":"outage","error_code":503}', false, false],
            ] as $name => [$status, $body, $accepted, $refused]) {
                yield ($admin ? 'admin ' : 'server failover ') . $name => [$admin, $status, $body, $accepted, $refused];
            }
        }
    }

    #[Test]
    #[DataProvider('originalTelegramResponses')]
    public function originalStatusAndApiAcknowledgementDetermineDelivery(bool $admin, int $status, string $body, bool $accepted, bool $refused): void
    {
        $first = new MockHttpClient(0 === $status ? $this->networkFailure(['pretransfer_time' => 0.01, 'size_upload' => 123.0, 'request_size' => 300]) : new MockResponse($body, ['http_code' => $status]));
        $second = new MockHttpClient(new MockResponse('{"ok":true,"result":{"message_id":2}}'));
        $server = new \Symfony\Component\Notifier\Transport\FailoverTransport([
            new TelegramTransport('123456:first', client: $first),
            new TelegramTransport('123456:second', client: $second),
        ]);
        if ($admin) {
            $this->token('123456:admin');
        }
        $connection = new TelegramConnection(static fn (): TransportInterface => $server, new NullLogger(), $this->bots(), $first);
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), new NullLogger(), $connection, new EventDispatcher(), true);
        $snapshot = [];
        $progress = new \Itk\Cms\Request\DeliveryProgress([], static function (string $key, ?array $part) use (&$snapshot): void { $snapshot[$key] = $part; });

        $outcome = $notifier->attempt(new Request('call'), progress: $progress);

        self::assertSame(!$accepted && !$refused, $outcome->uncertain);
        self::assertSame($accepted || (!$admin && $refused) ? Request::DELIVERY_SENT : Request::DELIVERY_FAILED, $outcome->state);
        self::assertSame($accepted || (!$admin && $refused) ? 'sent' : ($refused ? null : 'sending'), \array_values($snapshot)[0]['state'] ?? null);
        self::assertSame(1, $first->getRequestsCount());
        self::assertSame(!$admin && $refused ? 1 : 0, $second->getRequestsCount());
    }

    #[Test]
    #[DataProvider('telegramSources')]
    public function incompatibleTypedClientStaysConfiguredAndFailsAsRefusal(bool $admin): void
    {
        $wire = new MockHttpClient(new MockResponse('{"ok":true,"result":{"message_id":1}}'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = (string) \json_encode([$message, $context]);
            }
        };
        // Replacing RetryableHttpClient with the single-attempt adapter violates the concrete type below.
        $retrying = new \Symfony\Component\HttpClient\RetryableHttpClient($wire);
        $typed = new class($retrying) extends MockHttpClient {
            public function __construct(private \Symfony\Component\HttpClient\RetryableHttpClient $decorated)
            {
                parent::__construct();
            }

            /** @param array<string, mixed> $options */
            public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
            {
                return $this->decorated->request($method, $url, $options);
            }
        };
        if ($admin) {
            $this->token('123456:private-token');
        }
        $connection = new TelegramConnection(static fn (): TransportInterface => new TelegramTransport('123456:private-token', client: $typed), $logger, $this->bots(), $typed);
        $schema = $this->schema();
        $site = $this->siteSettings($schema);
        $notifier = new TelegramNotifier($schema, $site, $logger, $connection, new EventDispatcher(), true);
        self::assertTrue($notifier->isActive(), 'a configured incompatible adapter must still create a delivery job');
        self::assertSame($admin ? 'admin' : 'server', $notifier->source());
        $request = new Request('call');
        self::assertFalse($notifier->notify($request));
        self::assertSame(Request::DELIVERY_FAILED, $request->delivery);
        self::assertStringContainsString(I18n::t('http.telegram_transport_incompatible', ['reason' => '']), (string) $request->deliveryError);
        self::assertStringContainsString('decorated', (string) $request->deliveryError);
        self::assertStringNotContainsString('private-token', (string) $request->deliveryError);
        self::assertSame(0, $wire->getRequestsCount());
        self::assertNotNull($notifier->problem(false));
        $health = (new TelegramHealthCheck($notifier, $site, $schema))->check();
        self::assertSame('warn', $health->status);
        self::assertStringContainsString('decorated', $health->message);
        self::assertStringNotContainsString('private-token', \implode('', $logger->entries));
    }

    #[Test]
    public function aPreviousAcceptedResponseDoesNotHideTheNextPreSendFailure(): void
    {
        $wire = new MockHttpClient(new MockResponse('{"ok":true,"result":{"message_id":1}}'));
        $dispatcher = new EventDispatcher();
        $transport = \Itk\Cms\Request\TelegramTransportGuard::transport(new TelegramTransport('123456:server-token', client: $wire, dispatcher: $dispatcher));
        $message = (new ChatMessage('test'))->options((new TelegramOptions())->chatId('1'));
        $transport->send($message);
        $dispatcher->addListener(\Symfony\Component\Notifier\Event\MessageEvent::class, static fn (): never => throw new \RuntimeException('pre-send failure'));
        try {
            $transport->send($message);
            self::fail('an earlier accepted response must not make another send successful');
        } catch (\RuntimeException $failure) {
            self::assertSame('pre-send failure', $failure->getMessage());
        }
        self::assertSame(1, $wire->getRequestsCount());
    }

    #[Test]
    public function aReentrantListenerCannotReplaceTheOriginalAcceptedResponse(): void
    {
        $wire = new MockHttpClient([
            new MockResponse('{"ok":true,"result":{"message_id":1}}'),
            new MockResponse('{"description":"listener rate limited","error_code":429}', ['http_code' => 429]),
        ]);
        $dispatcher = new EventDispatcher();
        $transport = \Itk\Cms\Request\TelegramTransportGuard::transport(new TelegramTransport('123456:server-token', client: $wire, dispatcher: $dispatcher));
        $dispatcher->addListener(\Symfony\Component\Notifier\Event\SentMessageEvent::class, static function () use ($transport): void {
            $transport->send((new ChatMessage('listener message'))->options((new TelegramOptions())->chatId('2')));
        });

        self::assertNotNull($transport->send((new ChatMessage('original message'))->options((new TelegramOptions())->chatId('1'))));
        self::assertSame(2, $wire->getRequestsCount());
    }

    #[Test]
    #[DataProvider('listenerFailures')]
    public function aFailureListenerCannotReclassifyTheOriginalPost(string $composition, int $originalStatus, int $listenerStatus, bool $uncertain): void
    {
        $original = new MockHttpClient(new MockResponse('{"description":"original failure","error_code":' . $originalStatus . '}', ['http_code' => $originalStatus]));
        $listener = new MockHttpClient(new MockResponse('{"description":"unrelated listener failure","error_code":' . $listenerStatus . '}', ['http_code' => $listenerStatus]));
        $fallback = new MockHttpClient(new MockResponse('{"ok":true,"result":{"message_id":2}}'));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(\Symfony\Component\Notifier\Event\FailedMessageEvent::class, static function () use ($listener): void {
            (new TelegramTransport('123456:listener', client: $listener))->send((new ChatMessage('listener message'))->options((new TelegramOptions())->chatId('2')));
        });
        $bots = [new TelegramTransport('123456:original', client: $original, dispatcher: $dispatcher), new TelegramTransport('123456:fallback', client: $fallback)];
        $server = 'failover' === $composition
            ? new \Symfony\Component\Notifier\Transport\FailoverTransport($bots)
            : new class($bots) extends \Symfony\Component\Notifier\Transport\RoundRobinTransport {
                protected function getInitialCursor(): int
                {
                    return 0;
                }
            };
        $connection = new TelegramConnection(static fn (): TransportInterface => $server, new NullLogger());
        $schema = $this->schema();
        $notifier = new TelegramNotifier($schema, $this->siteSettings($schema), new NullLogger(), $connection, new EventDispatcher(), true);

        $outcome = $notifier->attempt(new Request('call'));

        self::assertSame($uncertain, $outcome->uncertain);
        self::assertSame($uncertain ? Request::DELIVERY_FAILED : Request::DELIVERY_SENT, $outcome->state);
        self::assertSame(1, $original->getRequestsCount());
        self::assertSame(1, $listener->getRequestsCount());
        self::assertSame($uncertain ? 0 : 1, $fallback->getRequestsCount());
    }

    /** @return iterable<string, array{string, int, int, bool}> */
    public static function listenerFailures(): iterable
    {
        foreach (['failover', 'roundrobin'] as $composition) {
            yield $composition . ' original 503 remains uncertain despite listener 429' => [$composition, 503, 429, true];
            yield $composition . ' original 408 remains uncertain despite listener 429' => [$composition, 408, 429, true];
            yield $composition . ' original 400 remains refused despite listener 503' => [$composition, 400, 503, false];
            yield $composition . ' original 429 remains refused despite listener 503' => [$composition, 429, 503, false];
        }
    }

    /** @param array<string, int|float> $info */
    #[Test]
    #[DataProvider('networkProgress')]
    public function anExceptionWithoutATrackedResponseStillUsesItsOwnNetworkEvidence(array $info, bool $uncertain): void
    {
        $response = $this->networkFailure($info);
        $leaf = $this->createStub(TransportInterface::class);
        $leaf->method('send')->willThrowException(new \Symfony\Component\Notifier\Exception\TransportException('network failure', $response, previous: new \Symfony\Component\HttpClient\Exception\TransportException('network failure')));
        $transport = new \Itk\Cms\Request\SingleAttemptTelegramTransport($leaf, new \Itk\Cms\Request\SingleAttemptHttpClient(new MockHttpClient()));

        try {
            $transport->send((new ChatMessage('original message'))->options((new TelegramOptions())->chatId('1')));
            self::fail('the network failure must propagate');
        } catch (\Itk\Cms\Request\DeliveryUncertainException) {
            self::assertTrue($uncertain);
        } catch (\Symfony\Component\Notifier\Exception\TransportException $failure) {
            self::assertFalse($uncertain);
            self::assertSame($response, $failure->getResponse());
        }
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
    public function theDefaultAdminHttpClientAlsoHasTheOneAttemptGuard(): void
    {
        $this->token('123456:admin-token');
        $connection = new TelegramConnection(null, new NullLogger(), $this->bots());
        $transport = $connection->transport();
        self::assertInstanceOf(\Itk\Cms\Request\SingleAttemptTelegramTransport::class, $transport);
        $transport = (new \ReflectionProperty($transport, 'transport'))->getValue($transport);
        self::assertInstanceOf(TelegramTransport::class, $transport);
        $client = (new \ReflectionProperty(\Symfony\Component\Notifier\Transport\AbstractTransport::class, 'client'))->getValue($transport);

        self::assertInstanceOf(\Itk\Cms\Request\SingleAttemptHttpClient::class, $client);
    }

    /** @param array<string, int|float> $info */
    #[Test]
    #[DataProvider('nativeNetworkProgress')]
    public function nativeFailuresUseConnectionProgressEvenThoughFinallySetsPretransferTime(array $info, bool $native, bool $refused): void
    {
        if ($native) {
            // A completed NativeResponse snapshot without opening a socket; NativeResponse::getInfo hides request_header.
            $class = new \ReflectionClass(\Symfony\Component\HttpClient\Response\NativeResponse::class);
            $response = $class->newInstanceWithoutConstructor();
            $class->getProperty('finalInfo')->setValue($response, $info + ['http_code' => 0, 'error' => 'network failure']);
            $response = new \Symfony\Component\HttpClient\Response\TraceableResponse(new MockHttpClient(), $response);
        } else {
            $response = new MockResponse('', $info + ['http_code' => 0, 'error' => 'network failure']);
        }
        $failure = new \Symfony\Component\Notifier\Exception\TransportException('Could not reach the remote Telegram server.', $response, previous: new \Symfony\Component\HttpClient\Exception\TransportException('network failure'));

        self::assertSame($refused, \Itk\Cms\Request\TelegramTransportGuard::refused($failure));
    }

    /** @return iterable<string, array{array<string, int|float>, bool, bool}> */
    public static function nativeNetworkProgress(): iterable
    {
        yield 'native DNS failure' => [['pretransfer_time' => 0.02, 'connect_time' => 0.0, 'starttransfer_time' => 0.0, 'size_upload' => 0], true, true];
        yield 'native refused TCP' => [['pretransfer_time' => 0.01, 'connect_time' => 0.0, 'starttransfer_time' => 0.0, 'size_upload' => 0], true, true];
        yield 'native pre-TLS/connect timeout' => [['pretransfer_time' => 5.0, 'connect_time' => 0.0, 'starttransfer_time' => 0.0, 'size_upload' => 0], true, true];
        yield 'native response timeout before upload metric' => [['pretransfer_time' => 5.0, 'connect_time' => 0.01, 'starttransfer_time' => 0.0, 'size_upload' => 0], true, false];
        yield 'native connected even with zero pretransfer metric' => [['pretransfer_time' => 0.0, 'connect_time' => 0.01, 'starttransfer_time' => 0.0, 'size_upload' => 0], true, false];
        yield 'native response lost after upload' => [['pretransfer_time' => 5.0, 'connect_time' => 0.01, 'starttransfer_time' => 0.02, 'size_upload' => 100], true, false];
        yield 'unknown client with similar zero connection metric' => [['pretransfer_time' => 5.0, 'connect_time' => 0.0, 'starttransfer_time' => 0.0, 'size_upload' => 0], false, false];
        yield 'native missing connection metrics' => [['pretransfer_time' => 5.0, 'size_upload' => 0], true, false];
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
        self::assertSame((string) $server, (string) $connection->transport());
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
        self::assertSame((string) $server, (string) $connection->transport());
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
            /** @var array<array-key, mixed> */
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

    /** @return iterable<array-key, mixed> */
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

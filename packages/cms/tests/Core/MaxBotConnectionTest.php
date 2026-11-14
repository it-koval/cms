<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\Max\MaxDeliveryException;
use Itk\Cms\Security\TwoFactor\SecretBox;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use Mj4444\SimpleHttpClient\Contracts\HttpClientInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpRequestInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpResponseInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class MaxBotConnectionTest extends TestCase
{
    /** @return array{BotSettings, EntityManagerInterface, SettingRepository} */
    private function settings(): array
    {
        $rows = [];
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('find')->willReturnCallback(static function (array $id) use (&$rows): ?Setting {
            return $rows[$id['key']] ?? null;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (Setting $row) use (&$rows): void {
            $rows[$row->key] = $row;
        });

        return [new BotSettings($em, $repo, new SecretBox('max-test-secret')), $em, $repo];
    }

    #[Test]
    public function adminTokensWinAndChangesAndClearTakeEffectOnTheSameSender(): void
    {
        [$settings] = $this->settings();
        $settings->save(['max' => ['token' => 'admin-first']]);
        $wire = new class implements HttpClientInterface {
            /** @var list<list<string>> */
            public array $headers = [];

            public function request(HttpRequestInterface $request): HttpResponseInterface
            {
                $this->headers[] = $request->getHeaders();

                return $request->makeResponse(200, $request->getUrl(), $request->getUrl(), null, [], 'application/json', '{"message": {}}');
            }
        };
        $server = (new MaxApiConfig('server-secret'))->setHttpClient($wire)->setRetryAttempts([]);
        $sender = new MaxApiSender(
            static fn (): MaxApiClient => new MaxApiClient($server),
            static fn (): MaxApiConfig => $server,
            new NullLogger(),
            $settings,
            static fn (string $token): MaxApiClient => new MaxApiClient((new MaxApiConfig($token))->setHttpClient($wire)->setRetryAttempts([])),
        );

        self::assertTrue($sender->isConfigured());
        self::assertSame('admin', $sender->source());
        $sender->send(1, 'one');
        $settings->save(['max' => ['token' => 'admin-second']]);
        $sender->send(1, 'two');
        $settings->save(['max' => ['clear' => true]]);
        self::assertSame('server', $sender->source());
        $sender->send(1, 'three');

        self::assertContains('Authorization: admin-first', $wire->headers[0]);
        self::assertContains('Authorization: admin-second', $wire->headers[1]);
        self::assertContains('Authorization: server-secret', $wire->headers[2]);
    }

    #[Test]
    public function aMissingServerTokenDoesNotBreakAWorkingAdminToken(): void
    {
        [$settings] = $this->settings();
        $settings->save(['max' => ['token' => 'admin-secret']]);
        $missing = static fn (): never => throw new \RuntimeException('Server token environment variable is absent');
        $sender = new MaxApiSender($missing, $missing, new NullLogger(), $settings);

        self::assertTrue($sender->isConfigured());
        self::assertSame('admin', $sender->source());
        self::assertNull($sender->problem());
    }

    #[Test]
    public function anUnreadableAdminTokenUsesTheServerFallbackAndReportsTheDamagedSetting(): void
    {
        [$settings, $em, $repo] = $this->settings();
        $settings->save(['max' => ['token' => 'admin-secret']]);
        $changedKey = new BotSettings($em, $repo, new SecretBox('changed-app-secret'));
        $server = new MaxApiConfig('server-secret');
        $sender = new MaxApiSender(static fn (): MaxApiClient => new MaxApiClient($server), static fn (): MaxApiConfig => $server, new NullLogger(), $changedKey);

        self::assertTrue($sender->isConfigured());
        self::assertSame('server', $sender->source());
        self::assertNotNull($sender->problem());
        self::assertStringNotContainsString('admin-secret', (string) $sender->problem());
    }

    #[Test]
    public function aClientBuildFailureNeverLogsAnUnknownServerSecret(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message . \json_encode($context);
            }
        };
        $bad = static fn (): never => throw new \RuntimeException('Authorization: server-secret');
        $sender = new MaxApiSender($bad, $bad, $logger);

        self::assertFalse($sender->isConfigured());
        self::assertNull($sender->source());
        self::assertNotNull($sender->problem());
        self::assertNotEmpty($logger->lines);
        self::assertStringNotContainsString('server-secret', \implode('', $logger->lines));
    }

    #[Test]
    public function aDeliveryErrorNeverIncludesEncodedFormsOfTheAdminToken(): void
    {
        [$settings] = $this->settings();
        $token = 'sensitive/+secret:value';
        $settings->save(['max' => ['token' => $token]]);
        $sender = new MaxApiSender(null, null, new NullLogger(), $settings, static fn (): never => throw new \RuntimeException($token . ' ' . \rawurlencode($token) . ' ' . \base64_encode($token)));

        self::assertSame('admin', $sender->source(), 'selection survives an unavailable connection');
        self::assertFalse($sender->isConfigured());
        try {
            $sender->send(1, 'text');
            self::fail('a broken client must not send');
        } catch (MaxDeliveryException $e) {
            foreach ([$token, \rawurlencode($token), \base64_encode($token)] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function anAdminTokenIsStillSelectedWhenTheOptionalClientPackageIsAbsent(): void
    {
        $loader = require \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $loader->setPsr4('MaxMessenger\\Bot\\', []);
        self::assertFalse(\class_exists(MaxApiClient::class));
        [$settings] = $this->settings();
        $settings->save(['max' => ['token' => 'admin-without-package']]);
        $sender = new MaxApiSender(null, null, new NullLogger(), $settings);

        self::assertSame('admin', $sender->source());
        self::assertFalse($sender->isConfigured());
        self::assertNotNull($sender->problem());
    }
}

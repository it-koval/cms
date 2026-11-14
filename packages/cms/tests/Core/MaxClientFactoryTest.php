<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Request\Max\MaxClientFactory;
use MaxMessenger\Bot\MaxApiConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final class MaxClientFactoryTest extends TestCase
{
    #[Test]
    public function anAdminTokenWithoutTheBundleUsesTheRussianEndpointAndItsCa(): void
    {
        $config = (new MaxClientFactory())->config('admin-secret');

        self::assertSame('https://platform-api2.max.ru', $config->getBaseUrl());
        self::assertSame('admin-secret', $config->getAccessToken()?->getValue());
        self::assertStringEndsWith('/russian_trusted_ca_bundle.pem', (string) $config->getCaCertificatePath());
        self::assertTrue($config->getVerifySslCertificate());
        self::assertSame([], $config->getRetryAttempts(), 'Messenger owns retries; a test send must not sleep for thirty seconds');
    }

    #[Test]
    public function anAdminTokenKeepsTheBundleTransportOptionsWithoutChangingTheServerToken(): void
    {
        $server = (new MaxApiConfig('server-secret', baseUrl: 'https://gateway.example.com'))
            ->setConnectTimeout(321)->setTimeout(456)->setCaCertificatePath('/private/ca.pem')
            ->setRetryAttempts([30000]);

        $config = (new MaxClientFactory())->config('admin-secret', $server);

        self::assertNotSame($server, $config);
        self::assertSame('admin-secret', $config->getAccessToken()?->getValue());
        self::assertSame('server-secret', $server->getAccessToken()?->getValue());
        self::assertSame('https://gateway.example.com', $config->getBaseUrl());
        self::assertSame(321, $config->getConnectTimeout());
        self::assertSame(456, $config->getTimeout());
        self::assertSame('/private/ca.pem', $config->getCaCertificatePath());
        self::assertSame([], $config->getRetryAttempts());
    }

    #[Test]
    public function aMissingServerTokenDoesNotPreventReadingIndependentBundleTransportOptions(): void
    {
        $options = [
            'max_bot.base_url' => 'https://gateway.example.com',
            'max_bot.connect_timeout' => 321,
            'max_bot.timeout' => 456,
            'max_bot.russian_trusted_ca' => false,
        ];
        $parameters = $this->createStub(ContainerBagInterface::class);
        $parameters->method('has')->willReturnCallback(static fn (string $key): bool => \array_key_exists($key, $options));
        $parameters->method('get')->willReturnCallback(static function (string $key) use ($options): mixed {
            if ('max_bot.access_token' === $key) {
                throw new \RuntimeException('Missing environment variable');
            }

            return $options[$key] ?? null;
        });

        $config = (new MaxClientFactory($parameters))->config('admin-secret');

        self::assertSame('https://gateway.example.com', $config->getBaseUrl());
        self::assertSame(321, $config->getConnectTimeout());
        self::assertSame(456, $config->getTimeout());
        self::assertNull($config->getCaCertificatePath());
        self::assertSame([], $config->getRetryAttempts());
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\Max\MaxClientFactory;
use Itk\Cms\Request\Max\MaxSender;
use MaxMessenger\Bot\MaxApiConfig;
use Mj4444\SimpleHttpClient\Contracts\HttpClientInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpRequestInterface;
use Mj4444\SimpleHttpClient\Contracts\HttpResponseInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaxOptionalBundleTest extends KernelTestCase
{
    #[Test]
    public function theInstalledBundleProvidesTheClientAndDeliversThroughItsConfiguredTransport(): void
    {
        if ('1' !== \getenv('ITK_TEST_MAX_BUNDLE')) {
            self::markTestSkipped('The optional MAX bundle is installed by its CI job.');
        }

        self::bootKernel(['environment' => 'max_bundle']);
        $container = self::getContainer();
        $config = $container->get('test.max_api_config');
        self::assertInstanceOf(MaxApiConfig::class, $config);
        $adminConfig = (new MaxClientFactory())->config('fixture-max-admin-token', $config);
        self::assertSame('fixture-max-admin-token', $adminConfig->getAccessToken()?->getValue());
        self::assertSame('https://max.example.test', $adminConfig->getBaseUrl());
        self::assertSame(125, $adminConfig->getConnectTimeout());
        self::assertSame(300, $adminConfig->getTimeout());

        $wire = $this->createMock(HttpClientInterface::class);
        $wire->expects(self::once())->method('request')->willReturnCallback(static function (HttpRequestInterface $request): HttpResponseInterface {
            self::assertSame('POST', $request->getMethod());
            self::assertStringStartsWith('https://max.example.test/messages?', $request->getUrl());
            self::assertStringContainsString('chat_id=-123', $request->getUrl());
            self::assertContains('Authorization: fixture-max-token', $request->getHeaders());
            $body = (array) \json_decode((string) $request->getBody()?->getBody(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('<b>Request</b>', $body['text']);
            self::assertSame('html', $body['format']);

            return $request->makeResponse(200, $request->getUrl(), $request->getUrl(), null, [], 'application/json', '{"message":{}}');
        });
        $config->setHttpClient($wire);

        $sender = $container->get(MaxSender::class);
        self::assertInstanceOf(MaxApiSender::class, $sender);
        self::assertTrue($sender->isConnected());
        self::assertSame('server', $sender->source());
        $sender->send(-123, '<b>Request</b>');
    }
}

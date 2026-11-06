<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\IndexNow\PingIndexNowHandler;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use Itk\Cms\Tests\Core\Fixture\RetryVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** PingIndexNowHandler: один POST на пачку с адресами без повторов, 200 и 202 принимаются, 429, 5xx и обрыв повторяются, пока транспорт не исчерпает max_retries, остальные 4xx нет; выключенное ничего не шлёт. */
final class IndexNowHandlerTest extends KernelTestCase
{
    use IndexNowSettings;

    /** @var list<array{method: string, url: string, body: array<string, mixed>, type: string}> */
    private array $requests = [];
    private IndexNowSite $site;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setting('domain', 'shop.example.org');
        $this->setting('indexNow', true);
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        parent::tearDown();
    }

    /** @param \Closure(): ResponseInterface $answer */
    private function handler(\Closure $answer): PingIndexNowHandler
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \json_decode((string) $options['body'], true), 'type' => $options['normalized_headers']['content-type'][0] ?? ''];

            return $answer();
        });
        $c = self::getContainer();
        $this->site = new IndexNowSite($c->get(SettingsService::class), $c->get(SitemapBuilder::class), $c->get(CacheItemPoolInterface::class), 'handler-secret', $http);

        return new PingIndexNowHandler($this->site, $http, 'https://engine.example.test/indexnow');
    }

    /**
     * @param list<string> $paths
     *
     * @return list<?\Throwable> what each job was told: null is an ack
     */
    private function drain(PingIndexNowHandler $handler, array $paths): array
    {
        $outcomes = [];
        foreach ($paths as $i => $path) {
            $handler(new PingIndexNow($path), new Acknowledger(PingIndexNowHandler::class, static function (?\Throwable $e) use (&$outcomes, $i): void {
                $outcomes[$i] = $e;
            }));
        }
        $handler->flush(true);
        \ksort($outcomes);

        return \array_values($outcomes);
    }

    #[Test]
    public function oneRequestCarriesTheDistinctAbsoluteAddressesAndTheKey(): void
    {
        $handler = $this->handler(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));

        $outcomes = $this->drain($handler, ['/about', '/about', '/новости/первая', '/']);

        self::assertSame([null, null, null, null], $outcomes);
        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame(['POST', 'https://engine.example.test/indexnow'], [$request['method'], $request['url']]);
        self::assertStringContainsString('application/json', $request['type']);
        self::assertStringContainsString('charset=utf-8', \strtolower($request['type']));
        self::assertSame([
            'host' => 'shop.example.org',
            'key' => $this->site->key(),
            'keyLocation' => 'https://shop.example.org/' . $this->site->key() . '.txt',
            'urlList' => ['https://shop.example.org/about', 'https://shop.example.org/%D0%BD%D0%BE%D0%B2%D0%BE%D1%81%D1%82%D0%B8/%D0%BF%D0%B5%D1%80%D0%B2%D0%B0%D1%8F', 'https://shop.example.org/'],
        ], $request['body']);
    }

    #[Test]
    public function aBatchIsAtMostAHundredMessages(): void
    {
        $handler = $this->handler(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));

        $this->drain($handler, \array_map(static fn (int $i): string => '/p' . $i, \range(1, 250)));

        self::assertSame([100, 100, 50], \array_map(static fn (array $r): int => \count($r['body']['urlList']), $this->requests));
    }

    #[Test]
    public function theSubmissionIsKeptForTheHealthLine(): void
    {
        $handler = $this->handler(static fn (): MockResponse => new MockResponse('', ['http_code' => 202]));

        $this->drain($handler, ['/a', '/b']);

        $last = $this->site->lastSubmission();
        self::assertSame([2, 202, null], [$last['count'], $last['status'], $last['error']]);
    }

    /** @return iterable<string, array{int}> */
    public static function accepted(): iterable
    {
        yield '200' => [200];
        yield '202, the key is checked later' => [202];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function anAcceptedBatchIsAcknowledged(int $status): void
    {
        $handler = $this->handler(static fn (): MockResponse => new MockResponse('', ['http_code' => $status]));

        self::assertSame([null, null], $this->drain($handler, ['/a', '/b']));
    }

    #[Test]
    public function aRefusalThatNoRetryCanChangeGoesToTheFailureTransport(): void
    {
        foreach ([400, 403, 422] as $status) {
            $handler = $this->handler(static fn (): MockResponse => new MockResponse('The url list is wrong', ['http_code' => $status]));

            $outcomes = $this->drain($handler, ['/a', '/b']);

            self::assertCount(2, $outcomes, (string) $status);
            foreach ($outcomes as $e) {
                self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $e, (string) $status);
                self::assertFalse(RetryVerdict::retried(new PingIndexNow('/a'), $e, 0), (string) $status);
                self::assertStringContainsString((string) $status, $e->getMessage());
                self::assertStringContainsString('The url list is wrong', $e->getMessage());
            }
        }
        $last = $this->site->lastSubmission();
        self::assertSame([422, 'The url list is wrong'], [$last['status'], $last['error']]);
    }

    /** @return iterable<string, array{\Closure(): MockResponse}> */
    public static function transient(): iterable
    {
        yield '429' => [static fn (): MockResponse => new MockResponse('slow down', ['http_code' => 429, 'response_headers' => ['Retry-After: 30']])];
        yield '503' => [static fn (): MockResponse => new MockResponse('', ['http_code' => 503])];
        yield 'no answer' => [static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused'])];
    }

    /** @param \Closure(): MockResponse $answer */
    #[Test]
    #[DataProvider('transient')]
    public function aTransientFailureIsRetriedUntilTheTransportGivesUp(\Closure $answer): void
    {
        $outcomes = $this->drain($this->handler($answer), ['/a', '/b']);

        self::assertCount(2, $outcomes);
        foreach ($outcomes as $e) {
            self::assertNotNull($e);
            self::assertTrue(RetryVerdict::retried(new PingIndexNow('/a'), $e, 0), 'the first failure is retried');
            self::assertFalse(RetryVerdict::retried(new PingIndexNow('/a'), $e, 3), 'after max_retries the message goes to the failure transport');
        }
    }

    #[Test]
    public function aRequestThatDoesNotCompleteIsKeptForTheHealthLine(): void
    {
        $this->drain($this->handler(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused'])), ['/a']);

        $last = $this->site->lastSubmission();
        self::assertSame(0, $last['status']);
        self::assertStringContainsString('connection refused', (string) $last['error']);
    }

    #[Test]
    public function withTheToggleTurnedOffAfterQueueingNothingIsSent(): void
    {
        $handler = $this->handler(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));
        $this->setting('indexNow', false);

        self::assertSame([null], $this->drain($handler, ['/a']));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function theAddressIsTheOneOfTheMomentTheBatchRuns(): void
    {
        $handler = $this->handler(static fn (): MockResponse => new MockResponse('', ['http_code' => 200]));
        $this->setting('domain', 'new-name.example.org');

        $this->drain($handler, ['/a']);

        self::assertSame(['https://new-name.example.org/a'], $this->requests[0]['body']['urlList']);
    }
}

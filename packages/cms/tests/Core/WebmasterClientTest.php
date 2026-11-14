<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\YandexWebmaster\Model\WebmasterApiException;
use Itk\Cms\YandexWebmaster\Model\WebmasterUnreachableException;
use Itk\Cms\YandexWebmaster\WebmasterClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** WebmasterClient: заголовок с токеном, адрес с закодированным host_id, ошибка Яндекса одним типом, обрыв связи другим. */
final class WebmasterClientTest extends TestCase
{
    private const TOKEN = 'y0_secret-token-1';

    /** @var list<array{method: string, url: string, auth: string, timeout: mixed}> */
    private array $requests = [];

    /** @param array<string, mixed> $body */
    private function client(int $status, array $body = [], ?string $base = 'https://wm.test/v4/'): WebmasterClient
    {
        return new WebmasterClient(new MockHttpClient(function (string $method, string $url, array $options) use ($status, $body): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'auth' => $options['normalized_headers']['authorization'][0] ?? '', 'timeout' => $options['timeout']];

            return new MockResponse(\json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
        }), $base ?? '');
    }

    #[Test]
    public function theTokenGoesInTheAuthorizationHeaderOnlyAndTheHostIdIsEncodedInThePath(): void
    {
        $this->client(200, ['sqi' => 5, 'searchable_pages_count' => 1, 'excluded_pages_count' => 0])->summary(self::TOKEN, 42, 'https:сайт.рф:443');

        self::assertSame('Authorization: OAuth ' . self::TOKEN, $this->requests[0]['auth']);
        self::assertSame('https://wm.test/v4/user/42/hosts/https%3A%D1%81%D0%B0%D0%B9%D1%82.%D1%80%D1%84%3A443/summary', $this->requests[0]['url']);
        self::assertStringNotContainsString(self::TOKEN, $this->requests[0]['url']);
        self::assertLessThanOrEqual(10, $this->requests[0]['timeout']);
    }

    /** @return iterable<string, array{int, ?string, bool}> */
    public static function rejections(): iterable
    {
        yield '401 without a body' => [401, null, true];
        yield '403 INVALID_OAUTH_TOKEN' => [403, 'INVALID_OAUTH_TOKEN', true];
        yield '403 for another reason' => [403, 'HOSTS_LIMIT_EXCEEDED', false];
        yield '404' => [404, 'HOST_NOT_FOUND', false];
    }

    #[Test]
    #[DataProvider('rejections')]
    public function anErrorOfYandexKeepsTheStatusAndTheCodeAndKnowsAnInvalidToken(int $status, ?string $code, bool $invalid): void
    {
        try {
            $this->client($status, null === $code ? [] : ['error_code' => $code])->userId(self::TOKEN);
            self::fail('expected an error');
        } catch (WebmasterApiException $e) {
            self::assertSame([$status, $code, $invalid], [$e->status, $e->errorCode, $e->isInvalidToken()]);
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    #[Test]
    public function aBusyOrBrokenYandexIsTemporary(): void
    {
        foreach ([429, 500, 503] as $status) {
            try {
                $this->client($status)->userId(self::TOKEN);
                self::fail('expected an error');
            } catch (WebmasterApiException $e) {
                self::assertTrue($e->isTemporary(), (string) $status);
            }
        }
    }

    #[Test]
    public function noAnswerIsAnotherKindOfFailure(): void
    {
        $client = new WebmasterClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'timeout'])), 'https://wm.test/v4');

        try {
            $client->userId(self::TOKEN);
            self::fail('expected a failure');
        } catch (WebmasterUnreachableException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    #[Test]
    public function aBodyThatIsNotJsonOnAnErrorIsStillAnApiError(): void
    {
        $client = new WebmasterClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('<html>Bad gateway</html>', ['http_code' => 502])), 'https://wm.test/v4');

        $this->expectException(WebmasterApiException::class);
        $client->userId(self::TOKEN);
    }

    #[Test]
    public function withoutAnHttpClientTheClientSaysSo(): void
    {
        self::assertFalse((new WebmasterClient(null, 'https://wm.test/v4'))->isAvailable());
        self::assertTrue($this->client(200)->isAvailable());
    }

    #[Test]
    public function theQueriesAreTheTenWithMostShowsAndAMissingIndicatorIsNull(): void
    {
        $queries = [];
        for ($i = 1; $i <= 12; ++$i) {
            $queries[] = ['query_id' => (string) $i, 'query_text' => 'q' . $i, 'indicators' => $i % 2 ? ['TOTAL_SHOWS' => (float) $i] : []];
        }

        $result = $this->client(200, ['queries' => $queries, 'date_from' => '2026-10-30T00:00:00', 'date_to' => '2026-11-05'])->popularQueries(self::TOKEN, 42, 'https:a.org:443');

        self::assertCount(10, $result['rows'] ?? []);
        self::assertSame(['q11', 11, null], [$result['rows'][0]['text'], $result['rows'][0]['shows'], $result['rows'][0]['clicks']]);
        self::assertSame(['2026-10-30', '2026-11-05'], [$result['from'], $result['to']]);
        self::assertNull($result['rows'][9]['shows'], 'queries without an indicator come last');
    }
}

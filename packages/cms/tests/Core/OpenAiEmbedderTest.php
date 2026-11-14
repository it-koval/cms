<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Embedding\OpenAiEmbedder;
use Itk\Cms\KnowledgeBase\Embedding\Purpose;
use Itk\Cms\KnowledgeBase\Embedding\Space;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Граница с OpenAI: запрос с размерностью 1536, ответ по index, 429 и 5xx временны (с Retry-After), 4xx и отсутствие клиента или ключа постоянны. */
final class OpenAiEmbedderTest extends TestCase
{
    private static function embedder(?HttpClientInterface $http, ?string $key = 'sk-test'): OpenAiEmbedder
    {
        return new OpenAiEmbedder($http, $key, 'text-embedding-3-small', 'https://api.example.test/v1', 5);
    }

    /** @param list<int> $order the indexes the API returns, in its own order */
    private static function answer(array $order): string
    {
        $data = [];
        foreach ($order as $i) {
            $data[] = ['index' => $i, 'embedding' => \array_fill(0, Space::DIMENSIONS, $i / 10)];
        }

        return (string) \json_encode(['data' => $data, 'usage' => ['total_tokens' => 7]]);
    }

    #[Test]
    public function inputsAreSentWithTheDimensionAndVectorsComeBackInInputOrder(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, \json_decode((string) $options['body'], true), $options['normalized_headers']['authorization'][0] ?? ''];

            return new MockResponse(self::answer([1, 0]));
        });

        $result = self::embedder($http)->embed(['один', 'два'], Purpose::Document);

        self::assertCount(1, $requests);
        self::assertSame(['POST', 'https://api.example.test/v1/embeddings'], [$requests[0][0], $requests[0][1]]);
        self::assertSame(['model' => 'text-embedding-3-small', 'input' => ['один', 'два'], 'encoding_format' => 'float', 'dimensions' => Space::DIMENSIONS], $requests[0][2]);
        self::assertSame('Authorization: Bearer sk-test', $requests[0][3]);
        self::assertSame(0.0, $result->vectors[0]->values[0], 'the first input got the vector with index 0, whatever the order of the answer');
        self::assertSame(0.1, $result->vectors[1]->values[0]);
        self::assertSame(7, $result->tokens);
        self::assertSame('openai:text-embedding-3-small:1536', self::embedder($http)->space()->id());
    }

    #[Test]
    public function aRateLimitIsTransientWithTheRetryAfterOfTheApi(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"Rate limit reached"}}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '20']]));

        try {
            self::embedder($http)->embed(['x'], Purpose::Query);
            self::fail('a 429 was accepted');
        } catch (EmbeddingFailed $e) {
            self::assertTrue($e->transient);
            self::assertSame(20, $e->retryAfter);
            self::assertStringContainsString('Rate limit reached', $e->getMessage());
            self::assertStringNotContainsString('sk-test', $e->getMessage());
        }
    }

    #[Test]
    public function aServerErrorAndATransportErrorAreTransient(): void
    {
        try {
            self::embedder(new MockHttpClient(new MockResponse('', ['http_code' => 503])))->embed(['x'], Purpose::Query);
            self::fail('a 503 was accepted');
        } catch (EmbeddingFailed $e) {
            self::assertTrue($e->transient);
            self::assertNull($e->retryAfter);
        }

        try {
            self::embedder(new MockHttpClient(new MockResponse('', ['error' => 'connection reset'])))->embed(['x'], Purpose::Query);
            self::fail('a transport error was accepted');
        } catch (EmbeddingFailed $e) {
            self::assertTrue($e->transient);
        }
    }

    #[Test]
    public function aBadKeyNoClientNoKeyAndAWrongSizeArePermanent(): void
    {
        foreach ([
            'bad key' => [new MockHttpClient(new MockResponse('{"error":{"message":"Incorrect API key"}}', ['http_code' => 401])), 'sk-test'],
            'no client' => [null, 'sk-test'],
            'no key' => [new MockHttpClient(), ''],
            'wrong size' => [new MockHttpClient(new MockResponse('{"data":[{"index":0,"embedding":[1,2,3]}]}')), 'sk-test'],
        ] as $case => [$http, $key]) {
            try {
                self::embedder($http, $key)->embed(['x'], Purpose::Document);
                self::fail($case . ' was accepted');
            } catch (EmbeddingFailed $e) {
                self::assertFalse($e->transient, $case);
            }
        }
    }
}

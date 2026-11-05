<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Embedder over the OpenAI REST API: POST {base_url}/embeddings { model, input: [...], dimensions: 1536, encoding_format: 'float' }.
 *
 * One attempt per request: the backoff after a 429 or a 5xx is the retry strategy of the `knowledge_base` transport (the worker side) or the caller's
 * own problem (a search: KnowledgeSearch turns it into KnowledgeBaseUnavailable). Inputs go in groups of BATCH.
 * The HTTP client is optional, as for UpdateChecker: only symfony/http-client-contracts is required by the bundle.
 *
 * @internal
 */
final class OpenAiEmbedder implements Embedder
{
    /** inputs per request: far under the API's 2048 inputs and 300k tokens per request with chunks of at most 2000 characters */
    private const BATCH = 256;

    public function __construct(
        private readonly ?HttpClientInterface $http,
        #[Autowire(env: 'default::OPENAI_API_KEY')]
        private readonly ?string $apiKey,
        #[Autowire(param: 'itk_cms.knowledge_base.model')]
        private readonly string $model,
        #[Autowire(param: 'itk_cms.knowledge_base.base_url')]
        private readonly string $baseUrl,
        #[Autowire(param: 'itk_cms.knowledge_base.timeout')]
        private readonly int $timeout,
    ) {
    }

    public function space(): Space
    {
        return new Space('openai', $this->model);
    }

    public function embed(array $texts, Purpose $purpose): Embeddings
    {
        if (null === $this->http) {
            throw EmbeddingFailed::permanent('symfony/http-client is not installed: composer require symfony/http-client');
        }
        if (null === $this->apiKey || '' === $this->apiKey) {
            throw EmbeddingFailed::permanent('OPENAI_API_KEY is not set');
        }
        $vectors = [];
        $tokens = 0;
        foreach (\array_chunk($texts, self::BATCH) as $group) {
            $body = ['model' => $this->model, 'input' => $group, 'encoding_format' => 'float'];
            if (\str_starts_with($this->model, 'text-embedding-3')) {
                $body['dimensions'] = Space::DIMENSIONS;
            }
            try {
                $response = $this->http->request('POST', $this->baseUrl . '/embeddings', ['auth_bearer' => $this->apiKey, 'json' => $body, 'timeout' => $this->timeout]);
                $status = $response->getStatusCode();
                if (200 !== $status) {
                    throw self::refused($status, $response);
                }
                $data = $response->toArray(false);
            } catch (HttpException $e) {
                throw EmbeddingFailed::transient('The embedding request did not complete: ' . $e->getMessage(), null, $e);
            }
            $rows = $data['data'] ?? null;
            if (!\is_array($rows) || \count($rows) !== \count($group)) {
                throw EmbeddingFailed::permanent(\sprintf('The embedding API answered with %s vectors for %d inputs.', \is_array($rows) ? \count($rows) : 'no', \count($group)));
            }
            $byIndex = [];
            foreach ($rows as $row) {
                $byIndex[(int) ($row['index'] ?? -1)] = Vector::of(\is_array($row['embedding'] ?? null) ? $row['embedding'] : []);
            }
            foreach (\array_keys($group) as $i) {
                $vectors[] = $byIndex[$i] ?? throw EmbeddingFailed::permanent('The embedding API answered without the vector of input ' . $i . '.');
            }
            $tokens += (int) ($data['usage']['total_tokens'] ?? 0);
        }

        return new Embeddings($vectors, $tokens);
    }

    /** 429 and 5xx are transient (with the Retry-After the API asked for); 4xx are the caller's: the key, the model, the request. */
    private static function refused(int $status, ResponseInterface $response): EmbeddingFailed
    {
        $reason = 'HTTP ' . $status;
        try {
            $error = $response->toArray(false)['error']['message'] ?? null;
            if (\is_string($error) && '' !== $error) {
                $reason .= ': ' . $error;
            }
        } catch (\Throwable) {
        }
        if (429 === $status || $status >= 500) {
            $after = $response->getHeaders(false)['retry-after'][0] ?? null;

            return EmbeddingFailed::transient('The embedding API refused the request (' . $reason . ')', \is_string($after) && \ctype_digit($after) ? (int) $after : null);
        }

        return EmbeddingFailed::permanent('The embedding API refused the request (' . $reason . ')');
    }
}

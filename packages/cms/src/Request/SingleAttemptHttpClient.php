<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Forces one HTTP attempt, including scoped retry options and POST-preserving redirects.
 *
 * @internal
 */
final class SingleAttemptHttpClient implements HttpClientInterface
{
    /** @var list<ResponseInterface|null> */
    private array $responses = [];

    public function __construct(private readonly HttpClientInterface $client, private readonly bool $retryable = false)
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['max_redirects'] = 0;
        if ($this->retryable) {
            $options['max_retries'] = 0;
        }

        $response = $this->client->request($method, $url, $options);
        $attempt = \array_key_last($this->responses);
        if (null !== $attempt && null === $this->responses[$attempt]) {
            $this->responses[$attempt] = $response;
        }

        return $response;
    }

    public function beginAttempt(): void
    {
        // A listener may send another message through this same transport before the outer send returns.
        $this->responses[] = null;
    }

    public function endAttempt(): void
    {
        \array_pop($this->responses);
    }

    public function accepted(): bool
    {
        $response = $this->response();
        try {
            $status = $response?->getStatusCode();
            if (null === $status || $status < 200 || $status >= 300) {
                return false;
            }
            $body = \json_decode($response->getContent(false), true, flags: \JSON_THROW_ON_ERROR);

            return \is_array($body) && true === ($body['ok'] ?? null) && isset($body['result']['message_id']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function response(): ?ResponseInterface
    {
        return $this->responses[\array_key_last($this->responses)] ?? null;
    }

    public function refused(): bool
    {
        $response = $this->response();
        $status = $response?->getInfo('http_code');

        return \is_int($status) && $status >= 400 && $status < 500 && 408 !== $status;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options), $this->retryable);
    }
}

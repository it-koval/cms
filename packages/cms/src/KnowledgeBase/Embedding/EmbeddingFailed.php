<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

/**
 * The embedder could not answer.
 *   transient:  rate limit, server error, timeout, network; the retry_strategy of the transport tries again later (retryAfter is what the provider asked for, the strategy decides)
 *   permanent:  wrong key, unknown model, bad request, no HTTP client, a vector of the wrong size (a retry would fail the same way)
 * The message never contains the key.
 */
final class EmbeddingFailed extends \RuntimeException
{
    private function __construct(string $message, public readonly bool $transient, public readonly ?int $retryAfter, ?\Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param int|null $retryAfter seconds the provider asked to wait (its Retry-After header), null when it did not say */
    public static function transient(string $message, ?int $retryAfter = null, ?\Throwable $previous = null): self
    {
        return new self($message, true, $retryAfter, $previous);
    }

    public static function permanent(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, false, null, $previous);
    }
}

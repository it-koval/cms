<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * The body of GET yandex-webmaster: what the admin shows about the site in Yandex.
 *
 * @internal
 */
final class WebmasterStatus
{
    /**
     * @param ?array{id: string, url: string} $host
     * @param ?array<string, mixed> $summary
     * @param ?array<string, mixed> $queries
     */
    public function __construct(
        public readonly WebmasterState $state,
        public readonly ?string $tokenLink,
        public readonly ?\DateTimeImmutable $connectedAt = null,
        public readonly ?\DateTimeImmutable $tokenExpiresAt = null,
        public readonly bool $tokenExpiresSoon = false,
        public readonly ?array $host = null,
        public readonly ?string $webmasterUrl = null,
        public readonly ?WebmasterVerification $verification = null,
        public readonly ?array $summary = null,
        public readonly ?array $queries = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?string $error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(bool $canManage): array
    {
        return [
            'state' => $this->state->value,
            'canManage' => $canManage,
            'tokenLink' => $this->tokenLink,
            'connectedAt' => $this->connectedAt?->format(\DATE_ATOM),
            'tokenExpiresAt' => $this->tokenExpiresAt?->format(\DATE_ATOM),
            'tokenExpiresSoon' => $this->tokenExpiresSoon,
            'host' => $this->host,
            'webmasterUrl' => $this->webmasterUrl,
            'verification' => null === $this->verification ? null : ['state' => $this->verification->state, 'failReason' => $this->verification->failReason],
            'summary' => $this->summary,
            'queries' => $this->queries,
            'updatedAt' => $this->updatedAt?->format(\DATE_ATOM),
            'error' => $this->error,
        ];
    }
}

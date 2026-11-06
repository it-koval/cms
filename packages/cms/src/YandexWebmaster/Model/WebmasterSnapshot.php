<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * What the CMS last learned from Yandex Webmaster: the check of ownership, the summary and the popular queries. It is replaced on every refresh, so it lives apart from the connection.
 * A failed refresh keeps the old data and sets `errorKey` (a translation key; the admin language is chosen when the status is built).
 *
 * `summary` is { sqi, searchable, excluded, problems: { FATAL, CRITICAL, POSSIBLE_PROBLEM, RECOMMENDATION } }, `queries` is { from, to, rows: [{ text, shows, clicks }] }: the shapes of the admin API.
 *
 * @internal
 */
final class WebmasterSnapshot
{
    /**
     * @param ?array<string, mixed> $summary
     * @param ?array<string, mixed> $queries
     * @param ?\DateTimeImmutable $updatedAt when the data was last fetched successfully
     * @param ?\DateTimeImmutable $checkedAt when a refresh last ran, whatever came of it
     */
    public function __construct(
        public readonly ?WebmasterVerification $verification = null,
        public readonly ?array $summary = null,
        public readonly ?array $queries = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?\DateTimeImmutable $checkedAt = null,
        public readonly ?string $errorKey = null,
        public readonly ?string $errorCode = null,
        public readonly bool $tokenInvalid = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'verification' => null === $this->verification ? null : ['state' => $this->verification->state, 'uin' => $this->verification->uin, 'failReason' => $this->verification->failReason],
            'summary' => $this->summary,
            'queries' => $this->queries,
            'updatedAt' => $this->updatedAt?->format(\DATE_ATOM),
            'checkedAt' => $this->checkedAt?->format(\DATE_ATOM),
            'errorKey' => $this->errorKey,
            'errorCode' => $this->errorCode,
            'tokenInvalid' => $this->tokenInvalid,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $v = $data['verification'] ?? null;
        $verification = \is_array($v) && \is_string($v['state'] ?? null)
            ? new WebmasterVerification($v['state'], \is_string($v['uin'] ?? null) ? $v['uin'] : null, \is_string($v['failReason'] ?? null) ? $v['failReason'] : null)
            : null;

        return new self(
            $verification,
            \is_array($data['summary'] ?? null) ? $data['summary'] : null,
            \is_array($data['queries'] ?? null) ? $data['queries'] : null,
            self::date($data['updatedAt'] ?? null),
            self::date($data['checkedAt'] ?? null),
            \is_string($data['errorKey'] ?? null) ? $data['errorKey'] : null,
            \is_string($data['errorCode'] ?? null) ? $data['errorCode'] : null,
            (bool) ($data['tokenInvalid'] ?? false),
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }
}

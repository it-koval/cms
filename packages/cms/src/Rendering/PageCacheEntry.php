<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

/**
 * A page as the cache keeps it: the HTML with the status and the Content-Type. Other headers are not kept: the templates set none.
 *
 * @internal
 */
final readonly class PageCacheEntry
{
    public function __construct(
        public string $html,
        public int $status,
        public string $contentType,
        /** Unix time the entry was made. */
        public int $created,
    ) {
    }

    /** @return array{html: string, status: int, contentType: string, created: int} */
    public function toArray(): array
    {
        return ['html' => $this->html, 'status' => $this->status, 'contentType' => $this->contentType, 'created' => $this->created];
    }

    /** The pool may hold anything after an upgrade; a value of another shape is not an entry. @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (!\is_string($data['html'] ?? null) || !\is_int($data['status'] ?? null) || !\is_string($data['contentType'] ?? null) || !\is_int($data['created'] ?? null)) {
            return null;
        }

        return new self($data['html'], $data['status'], $data['contentType'], $data['created']);
    }
}

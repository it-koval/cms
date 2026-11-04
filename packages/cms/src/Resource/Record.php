<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

/**
 * A light picture of one record for hooks, events and actions: a page (resourceKey = its template) or a collection item.
 * `fields` are the values of the version in `locale` (pages: the template fields; `meta` carries title, url, parentId, template).
 */
final class Record
{
    /**
     * @param 'page'|'item' $kind
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $resourceKey,
        public readonly string $locale,
        public readonly array $fields,
        public readonly string $status,
        public readonly array $meta = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->fields[$key] ?? $this->meta[$key] ?? $default;
    }
}

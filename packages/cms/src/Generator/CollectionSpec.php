<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Collection;

/**
 * What a collection class is generated from: a JSON file or a table.
 *
 * @internal
 */
final class CollectionSpec
{
    /**
     * @param list<FieldSpec> $fields
     * @param array<string, mixed>|null $source {kind: 'table'|'json', name: string, idColumn?: string}
     * @param list<string> $notes notices about ignored input
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $fields,
        public readonly ?string $singular = null,
        public readonly ?string $icon = null,
        public readonly ?string $group = null,
        public readonly ?array $source = null,
        public readonly array $notes = [],
    ) {
        $seen = [];
        foreach ($fields as $field) {
            if (\in_array($field->key, Collection::RESERVED_KEYS, true)) {
                throw new \InvalidArgumentException(I18n::t('model.problem_record_key_taken', ['at' => $title, 'key' => $field->key]));
            }
            if (isset($seen[$field->key])) {
                throw new \InvalidArgumentException(I18n::t('model.problem_field_key_repeated', ['at' => $title, 'key' => $field->key]));
            }
            $seen[$field->key] = true;
        }
    }

    public function withKeyAndTitle(string $key, string $title): self
    {
        return new self($key, $title, $this->fields, $this->singular, $this->icon, $this->group, $this->source, $this->notes);
    }

    /** The field the list shows as the name of a record: the first plain text, else the first field that is not a picture. */
    public function titleFieldKey(): ?string
    {
        foreach ($this->fields as $f) {
            if ('text' === $f->type) {
                return $f->key;
            }
        }
        foreach ($this->fields as $f) {
            if (!\in_array($f->type, ['image', 'photos'], true)) {
                return $f->key;
            }
        }

        return null;
    }
}

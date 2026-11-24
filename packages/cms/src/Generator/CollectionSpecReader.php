<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;

/**
 * A collection description from a JSON file: {key, title, singular?, icon?, group?, fields: [{key, label?, type, required?, max?, options?}]}.
 *
 * @internal
 */
final class CollectionSpecReader
{
    public function fromFile(string $file): CollectionSpec
    {
        if (!\is_file($file)) {
            throw new \InvalidArgumentException(I18n::t('model.gen_spec_missing', ['file' => $file]));
        }
        $data = \json_decode((string) \file_get_contents($file), true);
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(I18n::t('model.gen_spec_not_json', ['file' => $file, 'error' => \json_last_error_msg()]));
        }
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $i => $f) {
            if (!\is_array($f) || !isset($f['key'])) {
                throw new \InvalidArgumentException(I18n::t('model.gen_spec_key_needed', ['number' => $i + 1]));
            }
            $key = (string) $f['key'];
            $fields[] = new FieldSpec(
                $key,
                (string) ($f['label'] ?? Naming::label($key)),
                \strtolower((string) ($f['type'] ?? 'text')),
                (bool) ($f['required'] ?? false),
                isset($f['max']) ? (int) $f['max'] : null,
                \array_map('strval', \array_values((array) ($f['options'] ?? []))),
                $key,
            );
        }
        if ([] === $fields) {
            throw new \InvalidArgumentException(I18n::t('model.gen_spec_no_fields'));
        }
        $key = (string) ($data['key'] ?? '');
        $title = (string) ($data['title'] ?? ('' !== $key ? Naming::humanize($key) : ''));
        $notes = !empty($data['rows']) ? [I18n::t('ops.make_collection_rows_ignored')] : [];

        return new CollectionSpec(
            $key,
            $title,
            $fields,
            isset($data['singular']) ? (string) $data['singular'] : null,
            isset($data['icon']) ? (string) $data['icon'] : null,
            isset($data['group']) ? (string) $data['group'] : null,
            ['kind' => 'json', 'name' => \basename($file)],
            $notes,
        );
    }
}

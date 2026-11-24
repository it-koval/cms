<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Support;

use Itk\Cms\Content\FieldValueRules;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Repository\MediaFileRepository;
use Itk\Cms\Security\Access;

/**
 * Strict check of values a model sends for fields. The content services normalise quietly (they drop unknown keys, turn anything into a string); a model
 * is better served by a plain list of what is wrong, so it can fix its input. This only reports: the services still do their own cleaning and rights checks.
 *
 * @internal
 */
final class FieldInput
{
    private const MAX_ITEMS = 200;
    private const MAX_TEXT = 100_000;

    public function __construct(
        private readonly Access $access,
        private readonly MediaFileRepository $media,
    ) {
    }

    /**
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $data
     * @param list<string> $knownSrc image addresses already in the content (kept as they are)
     * @param array<string, mixed> $current what the content holds now: a read-only field sent back with the value it already has is not a change, so it is not a problem
     *
     * @return list<string> problems, each starting with the path of the field; empty = fine
     */
    public function problems(array $fields, array $data, array $knownSrc = [], string $path = '', array $current = []): array
    {
        $viewer = $this->access->viewer();
        $byKey = [];
        foreach ($fields as $f) {
            if (!$f->isNote()) {
                $byKey[$f->key()] = $f;
            }
        }
        $errors = [];
        foreach ($data as $key => $value) {
            $p = '' === $path ? (string) $key : $path . '.' . $key;
            $f = $byKey[$key] ?? null;
            if ('_id' === $key && '' !== $path) {
                continue; // id of a repeater item
            }
            if (null === $f || !$f->visibleTo($viewer)) {
                $errors[] = \sprintf('%s: unknown field (known: %s)', $p, \implode(', ', \array_keys(\array_filter($byKey, static fn (FieldInterface $x): bool => $x->visibleTo($viewer)))));
                continue;
            }
            if ($f->isLocked()) {
                if (!\array_key_exists($key, $current) || self::canonical($value) !== self::canonical($current[$key])) {
                    $errors[] = \sprintf('%s: this field is read-only', $p) . (\str_ends_with($path, ']') && [] === $current
                        ? ' (this item has no _id that matches a stored item: an item keeps its read-only values only when it carries the _id it had in the get result; send the existing items with their _id and leave this field out)'
                        : '');
                }
                continue;
            }
            \array_push($errors, ...$this->value($f, $value, $p, $knownSrc, \is_array($current[$key] ?? null) ? $current[$key] : []));
        }

        return $errors;
    }

    /**
     * A list (repeater) is replaced as a whole, so a raw field inside its items (Field::raw()) would vanish from the draft when the model leaves it out: it may not write
     * raw values, so it has no reason to send them. Here the value is carried over from the same item (found by _id) when the model did not send it.
     * An item that cannot be found (no _id) while the old list had raw values in other items would lose them without a trace: that is a problem to report, not a silent loss.
     * The same goes for an old item with a raw value and no _id of its own (written past the services): page_get shows it none, so nothing the model sends can point at it.
     * A nested list that holds raw values comes back whole when its item is sent without it: leaving the list out says nothing about the codes inside.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $data what the model sends, by field key
     * @param array<string, mixed> $current what the content holds now, by field key
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the data with the raw values put back, and the problems
     */
    public function keepRaw(array $fields, array $data, array $current, string $path = ''): array
    {
        $problems = [];
        foreach ($fields as $f) {
            $key = $f->key();
            if ('repeater' !== $f->type() || !\is_array($data[$key] ?? null) || !\is_array($current[$key] ?? null) || !\array_is_list($data[$key])) {
                continue;
            }
            $p = '' === $path ? $key : $path . '.' . $key;
            $old = self::rowsById($current[$key]);
            $claimed = [];
            $kept = [];
            $unmatched = false;
            foreach ($data[$key] as $i => $item) {
                $before = \is_array($item) && \is_string($item['_id'] ?? null) ? ($old[$item['_id']] ?? null) : null;
                if (!\is_array($item) || null === $before) {
                    $unmatched = $unmatched || \is_array($item);
                    continue;
                }
                $kept[$item['_id']] = true;
                $first = !isset($claimed[$item['_id']]);
                $claimed[$item['_id']] = true;
                $unmatched = $unmatched || !$first; // a twin of a row is as good as a new one: it takes the place of a stored row that nobody names
                foreach ($f->subFields() as $sub) {
                    if ($first && $sub->isLocked() && !\array_key_exists($sub->key(), $item) && \array_key_exists($sub->key(), $before)) {
                        $item[$sub->key()] = $before[$sub->key()]; // a read-only value is not the model's to write: the list is replaced as a whole, so it is carried over
                    }
                    if ($first && 'repeater' === $sub->type() && !\array_key_exists($sub->key(), $item) && self::holdsLocked([$sub], $before)) {
                        $item[$sub->key()] = $before[$sub->key()];
                    }
                    if ($sub->isRaw() && !\array_key_exists($sub->key(), $item) && \is_string($before[$sub->key()] ?? null) && '' !== \trim($before[$sub->key()])) {
                        $item[$sub->key()] = $before[$sub->key()];
                    }
                    if ('repeater' === $sub->type() && !\array_key_exists($sub->key(), $item) && self::holdsRaw([$sub], $before)) {
                        $item[$sub->key()] = $before[$sub->key()];
                    }
                }
                [$item, $deeper] = $this->keepRaw($f->subFields(), $item, $first ? $before : [], $p . '[' . $i . ']'); // a twin of a row gets nothing of it, deeper lists included
                \array_push($problems, ...$deeper);
                $data[$key][$i] = $item;
            }
            if ($unmatched) {
                $stored = [];
                foreach ($current[$key] as $i => $item) {
                    $id = \is_array($item) && \is_string($item['_id'] ?? null) ? $item['_id'] : null;
                    $twin = null !== $id && isset($stored[$id]); // a stored row that repeats the _id of an earlier one can never be told from it
                    if (null !== $id) {
                        $stored[$id] = true;
                    }
                    $raw = \is_array($item) && self::holdsRaw($f->subFields(), $item);
                    if (!$raw && !(\is_array($item) && self::holdsLocked($f->subFields(), $item))) {
                        continue;
                    }
                    $what = $raw ? 'raw HTML that cannot be written through a key' : 'read-only values that cannot be written through a key';
                    if (null === $id || $twin) {
                        $problems[] = \sprintf('%s[%d]: this item has %s and no _id of its own (or one that another item has too), so it cannot be told from the items you sent and would be lost. Leave this list as it is and tell the person to edit it in the admin', $p, $i, $what);
                    } elseif (!isset($kept[$id])) {
                        $problems[] = \sprintf('%s: the item %s has %s, and this list has items without a known _id, so it would be lost. Send the existing items with their _id as the get result shows them (the read-only and raw fields may be left out, they stay), or send the list without the new items to remove one', $p, $id, $what);
                    }
                }
            }
        }

        return [$data, $problems];
    }

    /**
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $item
     */
    private static function holdsRaw(array $fields, array $item): bool
    {
        foreach ($fields as $f) {
            $v = $item[$f->key()] ?? null;
            if ($f->isRaw() && \is_string($v) && '' !== \trim($v)) {
                return true;
            }
            if ('repeater' === $f->type() && \is_array($v)) {
                foreach ($v as $sub) {
                    if (\is_array($sub) && self::holdsRaw($f->subFields(), $sub)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $item
     */
    private static function holdsLocked(array $fields, array $item): bool
    {
        foreach ($fields as $f) {
            $v = $item[$f->key()] ?? null;
            if ($f->isLocked() && null !== $v && '' !== $v && [] !== $v) {
                return true;
            }
            if ('repeater' === $f->type() && \is_array($v)) {
                foreach ($v as $sub) {
                    if (\is_array($sub) && self::holdsLocked($f->subFields(), $sub)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The rows of a list by their _id; when a stored list names an _id twice, the first row stands for it.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rowsById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['_id'] ?? null)) {
                $out[$row['_id']] ??= $row;
            }
        }

        return $out;
    }

    /** A value in a form two equal values share, whatever the order of the keys of an object (an object and a list stay different). */
    private static function canonical(mixed $v): string
    {
        $sort = static function (mixed $x) use (&$sort): mixed {
            if (!\is_array($x)) {
                return $x;
            }
            $x = \array_map($sort, $x);
            if (!\array_is_list($x)) {
                \ksort($x);

                return (object) $x; // an object never equals a list, even when its keys are 0, 1, 2…
            }

            return $x;
        };

        return (string) \json_encode($sort($v));
    }

    /**
     * @param list<string> $knownSrc
     * @param array<array-key, mixed> $current what the field holds now (a list for a repeater)
     *
     * @return list<string>
     */
    private function value(FieldInterface $f, mixed $v, string $p, array $knownSrc, array $current = []): array
    {
        $input = $f->toArray()['inputType'] ?? '';
        $problems = FieldValueRules::problems($f, $v);
        if ([] !== $problems) {
            return \array_map(static fn (array $problem): string => $p . ': ' . match ($problem['code']) {
                'max' => \sprintf('at most %d characters (got %d)', $f->maxLength(), \mb_strlen((string) $v)),
                'choice' => 'must be one of: ' . \implode(', ', \array_column($f->choices(), 0)),
                'date' => 'date' === $input ? 'a date like 2026-11-05' : 'a date and time like 2026-11-05T14:30',
                default => $problem['message'],
            }, $problems);
        }
        switch ($f->type()) {
            case 'text':
            case 'textarea':
            case 'headline':
                if (!\is_string($v)) {
                    return [\sprintf('%s: must be a string', $p)];
                }
                if (\mb_strlen($v) > self::MAX_TEXT) {
                    return [\sprintf('%s: too long', $p)];
                }

                return [];
            case 'number':
                return \is_int($v) || \is_float($v) || '' === $v ? [] : [\sprintf('%s: must be a number', $p)];
            case 'toggle':
                return \is_bool($v) ? [] : [\sprintf('%s: must be true or false', $p)];
            case 'select':
                $values = \array_map(static fn (array $c): string => (string) $c[0], $f->choices());

                return \is_string($v) && ('' === $v || \in_array($v, $values, true)) ? [] : [\sprintf('%s: must be one of: %s', $p, \implode(', ', $values))];
            case 'image':
                return $this->image($v, $p, $knownSrc);
            case 'photos':
                if (!\is_array($v) || !\array_is_list($v)) {
                    return [\sprintf('%s: must be a list of image addresses (or of {src, alt})', $p)];
                }
                $errors = [];
                foreach ($v as $i => $item) {
                    // a string item stays a string (the content service keeps the shape of an item); it is checked like the src of an image
                    \array_push($errors, ...(\is_string($item) ? $this->src($item, $p . '[' . $i . ']', $knownSrc) : $this->image($item, $p . '[' . $i . ']', $knownSrc)));
                }

                return $errors;
            case 'link':
                if (!\is_array($v) || \array_is_list($v) && [] !== $v) {
                    return [\sprintf('%s: must be {"kind": "page"|"url"|"modal", "page": "/path", "url": "https://…"}', $p)];
                }
                $errors = [];
                if (isset($v['kind']) && !\in_array($v['kind'], ['page', 'url', 'modal'], true)) {
                    $errors[] = \sprintf('%s.kind: must be page, url or modal', $p);
                }
                foreach (['page', 'url'] as $k) {
                    if (isset($v[$k]) && !\is_string($v[$k])) {
                        $errors[] = \sprintf('%s.%s: must be a string', $p, $k);
                    }
                }
                foreach (\array_diff(\array_keys($v), ['kind', 'page', 'url']) as $k) {
                    $errors[] = \sprintf('%s.%s: unknown key (kind, page, url)', $p, $k);
                }

                return $errors;
            case 'repeater':
                if (!\is_array($v) || !\array_is_list($v)) {
                    return [\sprintf('%s: must be a list of objects', $p)];
                }
                if (\count($v) > self::MAX_ITEMS) {
                    return [\sprintf('%s: at most %d items', $p, self::MAX_ITEMS)];
                }
                $errors = [];
                $old = self::rowsById($current);
                foreach ($v as $i => $item) {
                    if (!\is_array($item) || (\array_is_list($item) && [] !== $item)) {
                        $errors[] = \sprintf('%s[%d]: must be an object', $p, $i);
                        continue;
                    }
                    $before = [];
                    if (\is_string($item['_id'] ?? null)) {
                        $before = $old[$item['_id']] ?? [];
                        unset($old[$item['_id']]); // only the first row that names an _id is the stored row: a copy of it holds no stored values
                    }
                    \array_push($errors, ...$this->problems($f->subFields(), $item, $knownSrc, $p . '[' . $i . ']', $before));
                }

                return $errors;
            case 'relation':
                $ok = \is_string($v) || (\is_array($v) && \array_is_list($v) && [] === \array_filter($v, static fn (mixed $x): bool => !\is_string($x)));

                return $ok ? [] : [\sprintf('%s: must be a record id (a list of ids for multiple)', $p)];
        }

        return [];
    }

    /**
     * @param list<string> $knownSrc
     *
     * @return list<string>
     */
    private function image(mixed $v, string $p, array $knownSrc): array
    {
        if (!\is_array($v) || (\array_is_list($v) && [] !== $v)) {
            return [\sprintf('%s: must be {"src": "…", "alt": "…"}', $p)];
        }
        $errors = [];
        foreach (\array_diff(\array_keys($v), ['src', 'alt']) as $k) {
            $errors[] = \sprintf('%s.%s: unknown key (src, alt)', $p, $k);
        }
        if (isset($v['alt']) && !\is_string($v['alt'])) {
            $errors[] = \sprintf('%s.alt: must be a string', $p);
        }
        $src = $v['src'] ?? '';
        if (!\is_string($src)) {
            return [...$errors, \sprintf('%s.src: must be a string', $p)];
        }

        return [...$errors, ...$this->src($src, $p . '.src', $knownSrc)];
    }

    /**
     * An image address must be one of the library, or one that is already in the content (kept as it is).
     *
     * @param list<string> $knownSrc
     *
     * @return list<string>
     */
    private function src(string $src, string $p, array $knownSrc): array
    {
        if ('' !== $src && !\in_array($src, $knownSrc, true) && null === $this->media->findOneBy(['path' => $src])) {
            return [\sprintf('%s: "%s" is not in the media library. Use a src from media_search, or upload the file with media_upload first', $p, \mb_substr($src, 0, 100))];
        }

        return [];
    }

    /**
     * Every image address in a content tree (to keep what is already there): the `src` of image and photo items and, when the fields of the data are
     * given, the address strings of Photos fields (they have no `src` key to be found by), also inside the rows of a Repeater.
     *
     * @param list<FieldInterface> $fields the fields $data belongs to
     *
     * @return list<string>
     */
    public static function srcsIn(mixed $data, array $fields = []): array
    {
        $out = self::srcKeys($data);
        if (\is_array($data)) {
            \array_push($out, ...self::photoStrings($fields, $data));
        }

        return $out;
    }

    /** @return list<string> */
    private static function srcKeys(mixed $data): array
    {
        $out = [];
        if (\is_array($data)) {
            foreach ($data as $k => $v) {
                if ('src' === $k && \is_string($v) && '' !== $v) {
                    $out[] = $v;
                } else {
                    \array_push($out, ...self::srcKeys($v));
                }
            }
        }

        return $out;
    }

    /**
     * @param list<FieldInterface> $fields
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function photoStrings(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $data[$f->key()] ?? null;
            if (!\is_array($v)) {
                continue;
            }
            if ('photos' === $f->type()) {
                foreach ($v as $item) {
                    if (\is_string($item) && '' !== $item) {
                        $out[] = $item;
                    }
                }
            } elseif ('repeater' === $f->type()) {
                foreach ($v as $row) {
                    if (\is_array($row)) {
                        \array_push($out, ...self::photoStrings($f->subFields(), $row));
                    }
                }
            }
        }

        return $out;
    }
}

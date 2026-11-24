<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\History\ChangeSource;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;

/**
 * Fields marked Field::raw() are output as raw HTML, so a script in one of them runs on the site and in the admin preview with the
 * session of whoever opens it. Adding or changing such a value therefore takes the right to manage users (administrator); everybody
 * else may keep, move or delete what is already there. Without a signed-in user (console import) nothing is checked.
 * Through an AI assistant (MCP, see ChangeSource) nobody changes such a field, an administrator's key included: a model that reads texts of visitors could be talked into
 * writing a script, and the page would run it for everyone. So for a key every field is compared in its place (section, list item), not the page as a whole: emptying a field or
 * copying a code into another one is a change too. What stays allowed is taking the whole section or list item away (the code goes with it, as in the admin), moving and hiding.
 *
 * @internal
 */
final class RawFieldGuard
{
    private const NO_ID = '#';

    public function __construct(
        private readonly Schema $schema,
        private readonly Access $access,
        private readonly ChangeSource $source,
    ) {
    }

    /**
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function assertFields(array $fields, array $before, array $after): void
    {
        $this->assertValues($this->values($fields, $before), $this->values($fields, $after));
    }

    /**
     * @param list<array<string, mixed>> $before sections
     * @param list<array<string, mixed>> $after sections
     */
    public function assertSections(array $before, array $after): void
    {
        $this->assertValues($this->sectionValues($before), $this->sectionValues($after));
    }

    /**
     * @param list<array{0: string, 1: string}> $before
     * @param list<array{0: string, 1: string}> $after
     */
    private function assertValues(array $before, array $after): void
    {
        if (ChangeSource::MCP === $this->source->via()) {
            $this->assertInPlace($before, $after);

            return;
        }
        if ([] === \array_diff(\array_column($after, 1), \array_column($before, 1), [''])) {
            return;
        }
        if (null === $this->access->user() || $this->access->can('set:users', 'edit')) {
            return;
        }

        throw ContentException::forbidden(I18n::t('content.raw_admin_only'));
    }

    /**
     * Every field that is there after the change must hold what it held before, and no value may stand in its place more often than it did: two items with one _id are one place,
     * and a value compared without counting would pass in both. A place that is gone (a section or an item taken away) is not looked at.
     *
     * @param list<array{0: string, 1: string}> $before
     * @param list<array{0: string, 1: string}> $after
     */
    private function assertInPlace(array $before, array $after): void
    {
        $left = [];
        foreach ($before as [$place, $value]) {
            $left[$place][] = $value;
        }
        $moved = [];
        foreach ($after as [$place, $value]) {
            $at = \array_search($value, $left[$place] ?? [], true);
            if (false === $at) {
                $moved[] = [$place, $value];
            } else {
                unset($left[$place][$at]);
            }
        }
        // a save gives a section or an item that has no id a new one: its code has a new place, the value is the same (each one once: it moved, it was not copied)
        $nameless = [];
        foreach ($left as $place => $values) {
            if (\str_contains($place, self::NO_ID)) {
                \array_push($nameless, ...\array_values(\array_diff($values, [''])));
            }
        }
        foreach ($moved as [$place, $value]) {
            $at = isset($left[$place]) ? false : \array_search($value, $nameless, true);
            if (false !== $at) {
                unset($nameless[$at]);
            } elseif ('' !== $value || [] !== ($left[$place] ?? [])) {
                throw ContentException::forbidden(I18n::t('content.raw_ai_forbidden'));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sectionValues(array $sections): array
    {
        $out = [];
        foreach ($sections as $i => $s) {
            $def = $this->schema->section((string) ($s['type'] ?? ''));
            if (null !== $def && \is_array($s['data'] ?? null)) {
                $out = [...$out, ...$this->values($def->fieldList(), $s['data'], self::place($s['id'] ?? null, $i))];
            }
        }

        return $out;
    }

    /**
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $data
     *
     * @return list<array{0: string, 1: string}> the raw fields, repeater items included: [its place, its value ('' when empty)]
     */
    private function values(array $fields, array $data, string $at = ''): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $data[$f->key()] ?? null;
            if ($f->isRaw()) {
                $out[] = [$at . $f->key(), \is_string($v) && '' !== \trim($v) ? $v : ''];
            } elseif ('repeater' === $f->type() && \is_array($v)) {
                foreach ($v as $i => $item) {
                    if (\is_array($item)) {
                        $out = [...$out, ...$this->values($f->subFields(), $item, $at . $f->key() . '/' . self::place($item['_id'] ?? null, $i))];
                    }
                }
            }
        }

        return $out;
    }

    /** A step of the path to a field: the id of the section or the list item, the position when it has none. */
    private static function place(mixed $id, int|string $position): string
    {
        return (\is_string($id) && '' !== $id ? $id : self::NO_ID . $position) . '/';
    }
}

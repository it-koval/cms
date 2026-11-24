<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;

/**
 * Unpublished-change detection for pages and collection records. Mirrors admin/src/utils/changes.js.
 *
 * @internal
 */
final class ChangeDetector
{
    public function __construct(
        private readonly Schema $schema,
        private readonly FieldValues $values,
        private readonly ChangeDescriber $describer,
    ) {
    }

    /**
     * Differences between a page's working copy and its published snapshot.
     * kinds: edit (field of a section), add, del, vis, move, meta (title, address, SEO), field (a field of the page's template).
     *
     * @param array<string, mixed> $page draft: { title, url, seo, sections, fields }
     * @param array<string, mixed>|null $published
     *
     * @return list<array<string, mixed>> { kind, sec, key?, name, label, val, old }
     */
    public function pageChanges(array $page, string $template, ?array $published): array
    {
        return $this->pageDifferences($page, $template, $published, true);
    }

    /** Count the same differences without describing rich text or repeater values. */
    public function pageChangeCount(array $page, string $template, ?array $published): int
    {
        return \count($this->pageDifferences($page, $template, $published, false));
    }

    /** @return list<array<string, mixed>> */
    private function pageDifferences(array $page, string $template, ?array $published, bool $describe): array
    {
        if (null === $published) {
            return [];
        }
        $out = [];
        $pubById = [];
        foreach ($published['sections'] ?? [] as $s) {
            $pubById[$s['id']] = $s;
        }
        $curIds = \array_map(static fn (array $s): string => (string) $s['id'], $page['sections'] ?? []);

        foreach (Schema::pageFields() as $f) {
            $a = Json::get($page, $f->key());
            $b = Json::get($published, $f->key());
            if (!$this->values->equal($f, $a, $b)) {
                if (!$describe) {
                    $out[] = [];
                    continue;
                }
                $d = $this->describer->describeChange($f, $a, $b);
                $out[] = ['kind' => 'meta', 'sec' => null, 'key' => $f->key(), 'name' => I18n::t('content.change_name_page'), 'label' => $f->label(), 'val' => $d['val'], 'old' => $d['old']];
            }
        }

        $tpl = $this->schema->template($template);
        if (null !== $tpl) {
            foreach ($tpl->fieldList() as $f) {
                $a = ($page['fields'] ?? [])[$f->key()] ?? null;
                $b = ($published['fields'] ?? [])[$f->key()] ?? null;
                if (!$this->values->equal($f, $a, $b)) {
                    if (!$describe) {
                        $out[] = [];
                        continue;
                    }
                    $d = $this->describer->describeChange($f, $a, $b);
                    $out[] = ['kind' => 'field', 'sec' => null, 'key' => $f->key(), 'name' => $tpl->title(), 'label' => isset($d['where']) ? $f->label() . ' · ' . $d['where'] : $f->label(), 'val' => $d['val'], 'old' => $d['old']];
                }
            }
        }

        foreach ($page['sections'] ?? [] as $s) {
            $name = $describe ? $this->sectionName((string) $s['type']) : '';
            $pub = $pubById[$s['id']] ?? null;
            if (null === $pub) {
                $out[] = $describe ? ['kind' => 'add', 'sec' => $s['id'], 'name' => $name, 'label' => I18n::t('content.change_new_section'), 'val' => '', 'old' => ''] : [];
                continue;
            }
            foreach ($this->schema->sectionFields((string) $s['type']) as $f) {
                $a = ($s['data'] ?? [])[$f->key()] ?? null;
                $b = ($pub['data'] ?? [])[$f->key()] ?? null;
                if (!$this->values->equal($f, $a, $b)) {
                    if (!$describe) {
                        $out[] = [];
                        continue;
                    }
                    $d = $this->describer->describeChange($f, $a, $b);
                    $out[] = ['kind' => 'edit', 'sec' => $s['id'], 'key' => $f->key(), 'name' => $name, 'label' => isset($d['where']) ? $f->label() . ' · ' . $d['where'] : $f->label(), 'val' => $d['val'], 'old' => $d['old']];
                }
            }
            if (!empty($pub['hidden']) !== !empty($s['hidden'])) {
                $out[] = $describe ? ['kind' => 'vis', 'sec' => $s['id'], 'name' => $name, 'label' => !empty($s['hidden']) ? I18n::t('content.change_section_hidden') : I18n::t('content.change_section_shown'), 'val' => '', 'old' => ''] : [];
            }
        }
        foreach ($published['sections'] ?? [] as $s) {
            if (!\in_array($s['id'], $curIds, true)) {
                $out[] = $describe ? ['kind' => 'del', 'sec' => null, 'name' => $this->sectionName((string) $s['type']), 'label' => I18n::t('content.change_section_deleted'), 'val' => '', 'old' => ''] : [];
            }
        }

        $a = \array_values(\array_filter(\array_map(static fn (array $s): string => (string) $s['id'], $published['sections'] ?? []), static fn (string $id): bool => \in_array($id, $curIds, true)));
        $b = \array_values(\array_filter($curIds, static fn (string $id): bool => isset($pubById[$id])));
        if ($a !== $b) {
            $out[] = $describe ? ['kind' => 'move', 'sec' => null, 'name' => I18n::t('content.change_sections_order'), 'label' => I18n::t('content.change_sections_moved'), 'val' => '', 'old' => ''] : [];
        }

        return $out;
    }

    /**
     * Section ids that carry at least one change.
     *
     * @param array<string, mixed> $page
     * @param array<string, mixed>|null $published
     *
     * @return list<string>
     */
    public function changedSectionIds(array $page, string $template, ?array $published): array
    {
        $ids = [];
        foreach ($this->pageChanges($page, $template, $published) as $c) {
            if (!empty($c['sec'])) {
                $ids[$c['sec']] = true;
            }
        }

        return \array_keys($ids);
    }

    /**
     * Changed top-level keys of one section.
     *
     * @param array<string, mixed> $section
     * @param array<string, mixed>|null $publishedSection
     *
     * @return list<string>
     */
    public function sectionChangedKeys(array $section, ?array $publishedSection): array
    {
        if (null === $publishedSection) {
            return [];
        }

        return $this->values->changedKeys($this->schema->sectionFields((string) $section['type']), $section['data'] ?? [], $publishedSection['data'] ?? []);
    }

    /**
     * Fields of a collection record that differ from its published copy: { field, label, val, old }.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed>|null $published
     *
     * @return list<array<string, mixed>>
     */
    public function recordChanges(Collection $collection, array $record, ?array $published): array
    {
        return $this->recordDifferences($collection, $record, $published, true);
    }

    public function recordChangeCount(Collection $collection, array $record, ?array $published): int
    {
        return \count($this->recordDifferences($collection, $record, $published, false));
    }

    /** @return list<array<string, mixed>> */
    private function recordDifferences(Collection $collection, array $record, ?array $published, bool $describe): array
    {
        if (null === $published) {
            return [];
        }
        if ('matrix' === $collection->viewName()) {
            $out = [];
            $cols = $record['cols'] ?? [];
            $pcols = $published['cols'] ?? [];
            if (!Json::same($cols, $pcols)) {
                $out[] = $describe ? ['field' => ['k' => 'cols', 'label' => I18n::t('content.matrix_cols_title')], 'label' => I18n::t('content.matrix_cols_title'), 'val' => I18n::t('content.matrix_cols_count', ['count' => \count($cols)]), 'old' => I18n::t('content.matrix_cols_count', ['count' => \count($pcols)])] : [];
            }
            $rows = $record['rows'] ?? [];
            $prows = $published['rows'] ?? [];
            if (!Json::same(Json::stripIds($rows), Json::stripIds($prows))) {
                $out[] = $describe ? ['field' => ['k' => 'rows', 'label' => I18n::t('content.matrix_rows_title')], 'label' => I18n::t('content.matrix_rows_title'), 'val' => I18n::t('content.matrix_rows_count', ['count' => \count($rows)]), 'old' => I18n::t('content.matrix_rows_count', ['count' => \count($prows)])] : [];
            }

            return $out;
        }
        $out = [];
        foreach ($collection->fieldList() as $f) {
            $a = $record[$f->key()] ?? null;
            $b = $published[$f->key()] ?? null;
            if (!$this->values->equal($f, $a, $b)) {
                if (!$describe) {
                    $out[] = [];
                    continue;
                }
                $d = $this->describer->describeChange($f, $a, $b);
                $out[] = ['field' => $f->toArray(), 'label' => isset($d['where']) ? $f->label() . ' · ' . $d['where'] : $f->label(), 'val' => $d['val'], 'old' => $d['old']] + (isset($d['where']) ? ['where' => $d['where']] : []);
            }
        }

        return $out;
    }

    private function sectionName(string $type): string
    {
        $t = $this->schema->section($type);

        return null !== $t ? $t->toArray()['short'] : $type;
    }
}

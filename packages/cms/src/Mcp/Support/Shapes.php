<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Support;

use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;

/**
 * The compact shapes of pages and records in tool results (the full admin shapes carry far more than a model needs).
 *
 * @internal
 */
final class Shapes
{
    public function __construct(
        private readonly Schema $schema,
    ) {
    }

    /**
     * 'draft' (never published, or taken off the site) | 'published' | 'published_with_unpublished_changes'.
     *
     * @param array<string, mixed> $flat a page or record in the shape of the services
     */
    public static function state(array $flat): string
    {
        if ('published' !== ($flat['status'] ?? 'draft')) {
            return 'draft';
        }

        return !empty($flat['changed']) || ($flat['changes'] ?? 0) > 0 ? 'published_with_unpublished_changes' : 'published';
    }

    /**
     * One row of pages_list.
     *
     * @param array<string, mixed> $row PageService::row()
     *
     * @return array<string, mixed>
     */
    public function pageRow(array $row): array
    {
        return [
            'id' => $row['id'], 'title' => $row['title'], 'url' => $row['url'], 'template' => $row['template'], 'parentId' => $row['parentId'],
            'state' => self::state($row), 'sections' => $row['sectionsCount'], 'updatedAt' => $row['updatedAt'],
        ];
    }

    /**
     * A page for page_get / the write tools.
     *
     * @param array<string, mixed> $flat PageService::flat()
     *
     * @return array<string, mixed>
     */
    public function page(array $flat, bool $withSections = true, ?string $onlySection = null): array
    {
        $sections = [];
        foreach ((array) Format::plain($flat['sections'] ?? []) as $s) {
            if (null !== $onlySection && $s['id'] !== $onlySection) {
                continue;
            }
            $sections[] = $withSections || null !== $onlySection ? $s : ['id' => $s['id'], 'type' => $s['type'], 'hidden' => (bool) ($s['hidden'] ?? false), 'summary' => $this->glance((string) $s['type'], (array) ($s['data'] ?? []))];
        }
        $out = [
            'id' => $flat['id'], 'template' => $flat['template'], 'parentId' => $flat['parentId'], 'locale' => $flat['locale'],
            'title' => $flat['title'], 'url' => $flat['url'], 'seo' => $flat['seo'],
            'state' => self::state($flat), 'updatedAt' => $flat['updatedAt'],
            'fields' => Format::plain($flat['fields'] ?? new \stdClass()) ?: new \stdClass(),
            'sections' => $sections,
        ];
        if (!empty($flat['awaiting'])) {
            $out['awaitingPublication'] = true;
        }

        return $out;
    }

    /**
     * What page_get and every page write tool answers: the page, whether it is live, and what differs from the live version.
     *
     * @param array<string, mixed> $view PageService::getDraft()
     *
     * @return array<string, mixed>
     */
    public function pageView(array $view, bool $withSections = true, ?string $onlySection = null): array
    {
        $page = $view['page'] + ['changed' => [] !== $view['changes']];
        $out = $this->page($page, $withSections, $onlySection);
        $out['live'] = null !== $view['published'];
        if ($out['live']) {
            $out['liveUrl'] = $view['published']['url'] ?? $out['url'];
        }
        $out['changes'] = Format::changes($view['changes']);

        return $out;
    }

    /**
     * A record of a collection for collection_item_get / the write tools.
     *
     * @param array<string, mixed> $flat CollectionService::flat()
     *
     * @return array<string, mixed>
     */
    public function item(Collection $c, array $flat): array
    {
        $skip = ['id' => 1, 'locale' => 1, 'translated' => 1, 'status' => 1, 'updatedAt' => 1, 'awaiting' => 1, 'returned' => 1, 'changed' => 1, 'changes' => 1, 'parentId' => 1, 'version' => 1];
        $fields = \array_diff_key($flat, $skip);

        return [
            'id' => $flat['id'], 'collection' => $c->key(), 'locale' => $flat['locale'], 'state' => self::state($flat), 'updatedAt' => $flat['updatedAt'],
        ] + ($c->isTree() ? ['parentId' => $flat['parentId'] ?? null] : []) + ['fields' => Format::plain($fields) ?: new \stdClass()];
    }

    /**
     * A row of collection_list.
     *
     * @param array<string, mixed> $row CollectionService::row()
     *
     * @return array<string, mixed>
     */
    public function itemRow(Collection $c, array $row): array
    {
        $title = $c->titleFieldKey();
        $values = [];
        foreach ($c->fieldList() as $f) {
            $k = $f->key();
            if ($k === $title || $f->isNote() || !\array_key_exists($k, $row) || !\in_array($f->type(), ['text', 'textarea', 'number', 'toggle', 'select', 'headline'], true)) {
                continue;
            }
            $v = $row[$k];
            if ('' === $v || null === $v || \count($values) >= 6) {
                continue;
            }
            $values[$k] = \is_string($v) ? Format::text($v, 80) : $v;
        }

        return [
            'id' => $row['id'], 'title' => Format::text($row[$title] ?? '', 120), 'state' => $row['translated'] ? self::state($row) : 'missing',
            'updatedAt' => $row['updatedAt'], 'values' => $values ?: new \stdClass(),
        ];
    }

    /**
     * First readable text of a section (its first text, headline or article field that is not empty): what it is about.
     *
     * @param array<string, mixed> $data
     */
    private function glance(string $type, array $data): string
    {
        foreach ($this->schema->section($type)?->fieldList() ?? [] as $f) {
            if (\in_array($f->type(), ['headline', 'text', 'textarea', 'article'], true) && \is_string($data[$f->key()] ?? null) && '' !== \trim(\strip_tags($data[$f->key()]))) {
                return Format::text($data[$f->key()], 80);
            }
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\History;

use Itk\Cms\Content\ChangeDescriber;
use Itk\Cms\Content\ChangeDetector;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;

/**
 * The shapes of a history entry (see Revision) and the list of changes between two of them.
 * A change row is { kind, name, label, val, old, key?, sec? }: the rows of ChangeDetector for pages and records, the same shape for settings and menus,
 * and one row without val / old for what happened to the whole object (kind: create | publish | unpublish | revert | delete | trash | baseline).
 *
 * @internal
 */
final class Snapshots
{
    /** Longer lists are cut: the entry says how many more there were. */
    public const MAX_ROWS = 60;

    private const SETTING_LABELS = [
        'tgOn' => 'content.setting_tg_on',
        'tgRoutes' => 'content.setting_tg_routes',
        'maxOn' => 'content.setting_max_on',
        'maxRoutes' => 'content.setting_max_routes',
        'mailRoutes' => 'content.setting_mail_routes',
        'forms' => 'content.setting_forms',
        'robotsTxt' => 'content.setting_robots_txt',
        'llmsTxt' => 'content.setting_llms_txt',
    ];

    public function __construct(
        private readonly Schema $schema,
        private readonly ChangeDetector $changes,
        private readonly ChangeDescriber $describer,
        private readonly FieldValues $values,
    ) {
    }

    /* ---------- shapes ---------- */

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public function page(string $template, ?string $parentId, string $status, array $draft): array
    {
        return ['template' => $template, 'parentId' => $parentId, 'status' => $status, 'draft' => $draft];
    }

    /** @return array<string, mixed> */
    public function pageOf(Page $p, PageTranslation $t): array
    {
        return $this->page($p->template, $p->parentId(), $t->status, $t->draft);
    }

    /** @return array<string, mixed> the whole page with all its language versions */
    public function pageRemoved(Page $p): array
    {
        $versions = [];
        foreach ($p->translations as $locale => $t) {
            $versions[(string) $locale] = [
                'draft' => $t->draft, 'published' => $t->published, 'status' => $t->status, 'publishedUrl' => $t->publishedUrl,
                'updatedAt' => $t->updatedAt->format(\DATE_ATOM), 'publishedAt' => $t->publishedAt?->format(\DATE_ATOM),
            ];
        }

        return [
            'page' => ['id' => $p->id, 'parentId' => $p->parentId(), 'position' => $p->position, 'template' => $p->template, 'createdAt' => $p->createdAt->format(\DATE_ATOM)],
            'versions' => $versions,
        ];
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public function item(string $status, array $draft): array
    {
        return ['status' => $status, 'draft' => $draft];
    }

    /**
     * One language version of a record. A record of a collection with a tree keeps its place too: a move changes nothing else.
     *
     * @param array<string, mixed> $t
     *
     * @return array<string, mixed>
     */
    public function itemOf(CollectionItem $i, array $t): array
    {
        $item = $this->item((string) ($t['status'] ?? 'draft'), (array) ($t['draft'] ?? []));

        return true === $this->schema->collection($i->collection)?->isTree() ? $item + ['parentId' => $i->parentId] : $item;
    }

    /** @return array<string, mixed> the whole record with all its language versions */
    public function itemRemoved(CollectionItem $i): array
    {
        return ['position' => $i->position, 'parentId' => $i->parentId, 'createdAt' => $i->createdAt->format(\DATE_ATOM), 'translations' => $i->translations];
    }

    /** The name to show for an entry: the title of a page, the title field of a record, the name of a group or a menu. */
    public function title(string $kind, string $key, array $snapshot): string
    {
        $title = '';
        if (Revision::PAGE === $kind) {
            $title = (string) ($snapshot['draft']['title'] ?? $this->firstVersion($snapshot)['draft']['title'] ?? '');
        } elseif (Revision::ITEM === $kind) {
            $field = $this->schema->collection($key)?->titleFieldKey() ?? 'title';
            $draft = $snapshot['draft'] ?? $this->firstVersion($snapshot['translations'] ?? [])['draft'] ?? [];
            $title = (string) ($draft[$field] ?? '');
        } elseif (Revision::MENU === $kind) {
            $title = (string) ($snapshot['name'] ?? $key);
        } else {
            $title = match ($key) {
                'notifications' => I18n::t('content.history_notifications'),
                'seo-files' => I18n::t('content.history_seo'),
                default => '',
            };
            foreach ($this->schema->settingsGroups() as $g) {
                if ($g->key() === $key) {
                    $title = $g->groupTitle();
                }
            }
        }
        $title = \trim((string) \preg_replace('/\s+/u', ' ', \strip_tags(\html_entity_decode($title, \ENT_QUOTES | \ENT_HTML5))));

        return \mb_substr($title, 0, 255);
    }

    /**
     * The version a deleted object shows by: the main language, else the first one.
     *
     * @param array<string, mixed> $versions
     *
     * @return array<string, mixed>
     */
    public function firstVersion(array $versions): array
    {
        $versions = $versions['versions'] ?? $versions;
        $main = $this->schema->mainLocale();

        return (array) ($versions[$main] ?? (\reset($versions) ?: []));
    }

    /* ---------- changes ---------- */

    /**
     * What `$snapshot` changed against `$prev` (null: nothing to compare with).
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $prev
     *
     * @return list<array<string, mixed>>
     */
    public function summary(string $kind, string $key, string $action, array $snapshot, ?array $prev): array
    {
        $head = $this->headRow($kind, $action);
        $rows = \in_array($action, [Revision::DELETE, Revision::DELETE_TRANSLATION, Revision::CREATE], true) ? [] : $this->diff($kind, $key, $snapshot, $prev);

        return $this->cut([...(null !== $head ? [$head] : []), ...$rows]);
    }

    /**
     * The differences between two versions of the same object: `$new` is what it will be, `$old` what it is.
     *
     * @param array<string, mixed> $new
     * @param array<string, mixed>|null $old
     *
     * @return list<array<string, mixed>>
     */
    public function diff(string $kind, string $key, array $new, ?array $old): array
    {
        if (null === $old) {
            return [];
        }

        return match ($kind) {
            Revision::PAGE => $this->pageDiff($key, $new, $old),
            Revision::ITEM => $this->itemDiff($key, $new, $old),
            Revision::SETTINGS => $this->settingsDiff($new, $old),
            Revision::MENU => $this->menuDiff($new, $old),
            default => [],
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function cut(array $rows): array
    {
        if (\count($rows) <= self::MAX_ROWS) {
            return $rows;
        }
        $rest = \count($rows) - self::MAX_ROWS;
        $rows = \array_slice($rows, 0, self::MAX_ROWS);
        $rows[] = ['kind' => 'more', 'name' => '', 'label' => I18n::t('content.history_more', ['count' => $rest]), 'val' => '', 'old' => ''];

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function headRow(string $kind, string $action): ?array
    {
        $noun = match ($kind) {
            Revision::PAGE => 'page',
            Revision::ITEM => 'item',
            Revision::MENU => 'menu',
            default => 'settings',
        };
        $created = 'content.head_created_' . $noun; // i18n-dynamic: content.head_created_
        $deleted = 'content.head_deleted_' . $noun; // i18n-dynamic: content.head_deleted_
        $label = match ($action) {
            Revision::CREATE => I18n::t($created),
            Revision::PUBLISH => I18n::t('content.head_published'),
            Revision::UNPUBLISH => I18n::t('content.head_unpublished'),
            Revision::REVERT => I18n::t('content.head_reverted'),
            Revision::DELETE => I18n::t($deleted),
            Revision::DELETE_TRANSLATION => I18n::t('content.head_translation_deleted'),
            Revision::RESTORE => I18n::t('content.head_restored'),
            default => null,
        };

        return null === $label ? null : ['kind' => $action, 'name' => '', 'label' => $label, 'val' => '', 'old' => ''];
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     *
     * @return list<array<string, mixed>>
     */
    private function pageDiff(string $template, array $new, array $old): array
    {
        $rows = [];
        if (($old['template'] ?? $template) !== ($new['template'] ?? $template)) {
            $rows[] = ['kind' => 'meta', 'name' => I18n::t('content.change_name_page'), 'label' => I18n::t('content.history_page_template'), 'val' => $this->templateTitle((string) $new['template']), 'old' => $this->templateTitle((string) $old['template']), 'sec' => null];
        }
        if (\array_key_exists('parentId', $old) && ($old['parentId'] ?? null) !== ($new['parentId'] ?? null)) {
            $rows[] = ['kind' => 'meta', 'name' => I18n::t('content.change_name_page'), 'label' => I18n::t('content.history_page_moved_label'), 'val' => I18n::t('content.history_moved'), 'old' => '', 'sec' => null];
        }

        return [...$rows, ...$this->changes->pageChanges((array) ($new['draft'] ?? []), (string) ($new['template'] ?? $template), (array) ($old['draft'] ?? []))];
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     *
     * @return list<array<string, mixed>>
     */
    private function itemDiff(string $collection, array $new, array $old): array
    {
        $c = $this->schema->collection($collection);
        if (null === $c) {
            return [];
        }
        $rows = [];
        if (\array_key_exists('parentId', $old) && \array_key_exists('parentId', $new) && $old['parentId'] !== $new['parentId']) {
            $rows[] = ['kind' => 'meta', 'name' => '', 'label' => I18n::t('content.history_item_moved_label'), 'val' => I18n::t('content.history_moved'), 'old' => ''];
        }
        foreach ($this->changes->recordChanges($c, (array) ($new['draft'] ?? []), (array) ($old['draft'] ?? [])) as $r) {
            $rows[] = ['kind' => 'field', 'key' => (string) ($r['field']['k'] ?? ''), 'name' => '', 'label' => (string) $r['label'], 'val' => $r['val'], 'old' => $r['old']];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     *
     * @return list<array<string, mixed>>
     */
    private function settingsDiff(array $new, array $old): array
    {
        $rows = [];
        foreach (\array_unique([...\array_keys($new), ...\array_keys($old)]) as $key) {
            $key = (string) $key;
            $a = $new[$key] ?? null;
            $b = $old[$key] ?? null;
            $f = $this->schema->settingsField($key);
            if (null !== $f) {
                if ($f->isNote() || $this->values->equal($f, $a, $b)) {
                    continue;
                }
                $d = $this->describer->describeChange($f, $a, $b);
                $rows[] = ['kind' => 'field', 'key' => $key, 'name' => '', 'label' => isset($d['where']) && '' !== $d['where'] ? $f->label() . ' · ' . $d['where'] : $f->label(), 'val' => $d['val'], 'old' => $d['old']];
            } elseif (!Json::same($a, $b)) {
                $rows[] = ['kind' => 'field', 'key' => $key, 'name' => '', 'label' => isset(self::SETTING_LABELS[$key]) ? I18n::t(self::SETTING_LABELS[$key]) : $key, 'val' => $this->describer->describeValue(null, $a), 'old' => $this->describer->describeValue(null, $b)];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     *
     * @return list<array<string, mixed>>
     */
    private function menuDiff(array $new, array $old): array
    {
        $rows = [];
        if (($new['name'] ?? '') !== ($old['name'] ?? '')) {
            $rows[] = ['kind' => 'field', 'name' => '', 'label' => I18n::t('content.history_menu_name'), 'val' => (string) ($new['name'] ?? ''), 'old' => (string) ($old['name'] ?? '')];
        }
        $a = $this->flatItems((array) ($new['items'] ?? []));
        $b = $this->flatItems((array) ($old['items'] ?? []));
        foreach ($a as $id => $it) {
            if (!isset($b[$id])) {
                $rows[] = ['kind' => 'add', 'name' => '', 'label' => I18n::t('content.history_menu_item_new'), 'val' => $it['label'], 'old' => ''];
                continue;
            }
            if ($it['label'] !== $b[$id]['label']) {
                $rows[] = ['kind' => 'edit', 'name' => '', 'label' => I18n::t('content.history_menu_item_name'), 'val' => $it['label'], 'old' => $b[$id]['label']];
            }
            if ($it['link'] !== $b[$id]['link']) {
                $rows[] = ['kind' => 'edit', 'name' => '', 'label' => I18n::t('content.history_menu_item_link', ['title' => $it['label']]), 'val' => $it['link'], 'old' => $b[$id]['link']];
            }
        }
        foreach ($b as $id => $it) {
            if (!isset($a[$id])) {
                $rows[] = ['kind' => 'del', 'name' => '', 'label' => I18n::t('content.history_menu_item_deleted'), 'val' => $it['label'], 'old' => ''];
            }
        }
        $order = static fn (array $list, array $other): array => \array_values(\array_filter(\array_keys($list), static fn ($id): bool => isset($other[$id])));
        if ($order($a, $b) !== $order($b, $a)) {
            $rows[] = ['kind' => 'move', 'name' => '', 'label' => I18n::t('content.history_menu_items_moved'), 'val' => '', 'old' => ''];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array{label: string, link: string}> by the id of the item, children after their parent
     */
    private function flatItems(array $items): array
    {
        $out = [];
        foreach ($items as $i => $it) {
            $link = (array) ($it['link'] ?? []);
            $id = (string) ($it['_id'] ?? 'n' . $i . ':' . ($it['label'] ?? ''));
            $out[$id] = ['label' => (string) ($it['label'] ?? ''), 'link' => $this->describer->describeValue(null, $link)];
            foreach ($this->flatItems((array) ($it['children'] ?? [])) as $cid => $child) {
                $out[$cid] = $child;
            }
        }

        return $out;
    }

    private function templateTitle(string $key): string
    {
        return $this->schema->template($key)?->title() ?? $key;
    }
}

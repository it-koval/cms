<?php

declare(strict_types=1);

namespace Itk\Cms\History;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Revision;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Repository\MenuAreaRepository;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * What the admin does with the history (the API of GET|POST history/…): lists, one entry with what returning to it would change, the trash, and the return itself.
 * Every call checks the rights of the signed-in person: reading needs `view`, returning to a version and restoring from the trash need `edit` (there is no
 * separate right to create: a new page or record needs `edit` too) of the thing the entry belongs to (pages by template, `col:<key>`, `set:<group>`, `set:menus`). The fields a person may not see (canSee) are left out of what he reads.
 */
final class HistoryAdmin
{
    public const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly RevisionRepository $revisions,
        private readonly HistoryService $history,
        private readonly Snapshots $snapshots,
        private readonly PageService $pages,
        private readonly CollectionService $collections,
        private readonly SettingsService $settings,
        private readonly MenuService $menus,
        private readonly PageRepository $pageRepo,
        private readonly CollectionItemRepository $items,
        private readonly MenuAreaRepository $areas,
        private readonly Schema $schema,
        private readonly FieldAccess $fields,
        private readonly Access $access,
    ) {
    }

    /**
     * The entries of one object in one language, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int, keep: int, days: int}
     */
    public function entries(string $kind, string $id, ?string $locale, int $limit = 50, int $offset = 0): array
    {
        $locale ??= $this->schema->mainLocale();
        $limit = \max(1, \min(self::MAX_PER_PAGE, $limit));
        $offset = \max(0, $offset);
        $key = $this->keyOf($kind, $id);
        $this->need($kind, $key, 'view');

        $rows = $this->revisions->history($kind, $id, $locale, $limit, $offset);

        return [
            'items' => \array_map(fn (Revision $r): array => $this->present($r), $rows),
            'total' => $this->revisions->countOf($kind, $id, $locale),
            'limit' => $limit,
            'offset' => $offset,
            'keep' => $this->history->keep(),
            'days' => $this->history->days(),
        ];
    }

    /**
     * One entry and what returning to it would change now (`against`: the rows of the changes, `val` = what it has, `old` = what the thing has now).
     *
     * @return array<string, mixed>
     */
    public function entry(int $id): array
    {
        $r = $this->get($id);
        $this->needRevision($r, 'view');
        $out = $this->present($r);
        $out['against'] = [];
        $out['current'] = false;
        $out['inTrash'] = false;
        if (Revision::DELETE_TRANSLATION === $r->action) {
            $out['canRestore'] = false;

            return $out;
        }
        if (Revision::DELETE === $r->action) {
            $out['inTrash'] = $this->inTrash($r);
            $out['locales'] = \array_map('strval', \array_keys((array) ($r->snapshot['versions'] ?? $r->snapshot['translations'] ?? [])));

            return $out;
        }
        $latest = $this->revisions->latest($r->kind, $r->resourceId, $r->locale);
        $out['current'] = null !== $latest && $latest->id === $r->id;
        $now = $this->now($r);
        if (null !== $now) {
            $rows = $this->snapshots->diff($r->kind, $r->resourceKey, $r->snapshot, $now);
            $out['against'] = $this->visibleRows($r, $this->snapshots->cut($rows));
            $out['same'] = [] === $rows;
        }

        return $out;
    }

    /**
     * «Вернуть эту версию»: the draft becomes what the entry holds (and the return is recorded in the history itself); for a deletion — the page or record comes back.
     * Nothing goes live by itself: what was on the site stays until the person publishes.
     *
     * @return array<string, mixed> { ok, kind, id, key, locale, restored: 'version'|'trash' }
     */
    public function restore(int $id): array
    {
        $r = $this->get($id);
        $this->needRevision($r, 'edit');
        if (Revision::DELETE_TRANSLATION === $r->action) {
            throw ContentException::invalid(I18n::t('content.history_cannot_restore'));
        }
        $base = ['ok' => true, 'kind' => $r->kind, 'id' => $r->resourceId, 'key' => $r->resourceKey, 'locale' => $r->locale];

        if (Revision::DELETE === $r->action) {
            if (!$this->inTrash($r)) {
                throw ContentException::conflict(I18n::t('content.history_already_restored'));
            }
            if (Revision::PAGE === $r->kind) {
                $this->pages->restoreRemoved($r->snapshot);
            } else {
                $this->collections->restoreRemoved($r->resourceKey, $r->resourceId, $r->snapshot);
            }

            return $base + ['restored' => 'trash'];
        }

        match ($r->kind) {
            Revision::PAGE => $this->pages->restoreVersion($r->resourceId, $r->locale, (array) ($r->snapshot['draft'] ?? [])),
            Revision::ITEM => $this->collections->restoreVersion($r->resourceKey, $r->resourceId, (array) ($r->snapshot['draft'] ?? []), $r->locale),
            Revision::SETTINGS => $this->settings->update($r->snapshot, $r->locale, Revision::RESTORE),
            Revision::MENU => $this->menus->saveMenus([$r->resourceId => (array) ($r->snapshot['items'] ?? [])], $r->locale, Revision::RESTORE),
            default => throw ContentException::invalid(I18n::t('content.history_cannot_restore')),
        };

        return $base + ['restored' => 'version'];
    }

    /**
     * The trash: pages and records deleted and not restored, newest first. Only what the person may see.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function trash(?string $kind = null, ?string $key = null): array
    {
        $kinds = null === $kind || '' === $kind ? [Revision::PAGE, Revision::ITEM] : [$kind];
        if ([] !== \array_diff($kinds, [Revision::PAGE, Revision::ITEM])) {
            throw ContentException::invalid(I18n::t('content.history_trash_kinds'));
        }
        $out = [];
        foreach ($this->revisions->trash($kinds, $key) as $r) {
            if (!$this->mayRevision($r, 'view')) {
                continue;
            }
            $versions = (array) ($r->snapshot['versions'] ?? $r->snapshot['translations'] ?? []);
            $out[] = [
                'id' => $r->id,
                'kind' => $r->kind,
                'resourceId' => $r->resourceId,
                'key' => $r->resourceKey,
                'keyTitle' => Revision::PAGE === $r->kind ? ($this->schema->template($r->resourceKey)?->title() ?? $r->resourceKey) : ($this->schema->collection($r->resourceKey)?->title() ?? $r->resourceKey),
                'title' => $this->visibleTitle($r),
                'userId' => $r->userId,
                'userName' => $r->userName,
                'deletedAt' => $r->createdAt->format(\DATE_ATOM),
                'locales' => \array_map('strval', \array_keys($versions)),
                'wasLive' => [] !== \array_filter($versions, static fn ($v): bool => 'published' === ($v['status'] ?? '')),
                'canRestore' => $this->mayRevision($r, 'edit'),
                'restorable' => $this->restorable($r),
            ];
        }

        return ['items' => $out, 'total' => \count($out)];
    }

    /* ---------- internals ---------- */

    private function get(int $id): Revision
    {
        return $this->revisions->find($id) ?? throw ContentException::notFound(I18n::t('content.history_entry_not_found'));
    }

    /** Is the deletion the last thing that happened to the page or record? */
    private function inTrash(Revision $r): bool
    {
        return Revision::DELETE === $r->action && $this->revisions->lastOf($r->kind, $r->resourceId)?->id === $r->id;
    }

    /** Can the page or record come back (the template or collection still exists, nothing else took its id)? */
    private function restorable(Revision $r): bool
    {
        if (Revision::PAGE === $r->kind) {
            return null !== $this->schema->template($r->resourceKey) && null === $this->pageRepo->find($r->resourceId);
        }

        return null !== $this->schema->collection($r->resourceKey) && null === $this->items->find($r->resourceId);
    }

    /** The template, collection, group or area an object belongs to: the thing the rights are about. */
    private function keyOf(string $kind, string $id): string
    {
        if (!\in_array($kind, Revision::KINDS, true)) {
            throw ContentException::notFound(I18n::t('content.history_not_found'));
        }

        return match ($kind) {
            Revision::PAGE => $this->pageRepo->find($id)->template ?? $this->lastKey($kind, $id),
            Revision::ITEM => $this->items->find($id)->collection ?? $this->lastKey($kind, $id),
            default => $id,
        };
    }

    private function lastKey(string $kind, string $id): string
    {
        return $this->revisions->lastOf($kind, $id)->resourceKey ?? throw ContentException::notFound(Revision::PAGE === $kind ? I18n::t('content.page_not_found') : I18n::t('content.item_not_found'));
    }

    private function need(string $kind, string $key, string $action): void
    {
        [$area, $template] = $this->area($kind, $key);
        $this->access->require($area, $action, $template);
        if (Revision::ITEM === $kind && !($this->schema->collection($key)?->isVisibleTo($this->access->viewer()) ?? false)) {
            throw new AccessDeniedException(I18n::t('content.no_permission'));
        }
    }

    /**
     * The keys the rights of a revision are checked against. A page may have changed its template since the revision was made:
     * the person needs the right on the template the page has now and on the one the revision was made under.
     *
     * @return list<string>
     */
    private function keysOf(Revision $r): array
    {
        if (Revision::PAGE !== $r->kind) {
            return [$r->resourceKey];
        }
        $now = $this->pageRepo->find($r->resourceId)->template ?? $this->revisions->lastOf(Revision::PAGE, $r->resourceId)->resourceKey ?? null; // a deleted page: the template it had last
        $held = $r->snapshot['template'] ?? null; // what the entry holds (an entry merged across a template change before it was split kept the older key)

        return \array_values(\array_unique(\array_filter([$r->resourceKey, $now, \is_string($held) ? $held : null], static fn (?string $k): bool => null !== $k && '' !== $k)));
    }

    private function needRevision(Revision $r, string $action): void
    {
        foreach ($this->keysOf($r) as $key) {
            $this->need($r->kind, $key, $action);
        }
    }

    private function mayRevision(Revision $r, string $action): bool
    {
        foreach ($this->keysOf($r) as $key) {
            if (!$this->may($r->kind, $key, $action)) {
                return false;
            }
        }

        return true;
    }

    private function may(string $kind, string $key, string $action): bool
    {
        [$area, $template] = $this->area($kind, $key);
        if (Revision::ITEM === $kind && !($this->schema->collection($key)?->isVisibleTo($this->access->viewer()) ?? false)) {
            return false;
        }

        return $this->access->can($area, $action, $template);
    }

    /** @return array{0: string, 1: string|null} the area of rights and the template for it */
    private function area(string $kind, string $key): array
    {
        return match ($kind) {
            Revision::PAGE => ['pages', $key],
            Revision::ITEM => ['col:' . $key, null],
            Revision::MENU => ['set:menus', null],
            default => ['set:' . ('seo-files' === $key ? 'seo' : $key), null], // robots.txt and llms.txt: the area of «SEO»
        };
    }

    /** The version the thing has now, in the shape of the entries (null: it is not there any more). @return null|array<string, mixed> */
    private function now(Revision $r): ?array
    {
        switch ($r->kind) {
            case Revision::PAGE:
                $p = $this->pageRepo->find($r->resourceId);
                $t = $p?->translation($r->locale);

                return null !== $p && null !== $t ? $this->history->pageState($p, $t) : null;

            case Revision::ITEM:
                $i = $this->items->find($r->resourceId);

                return null !== $i ? $this->history->itemState($i, $r->locale) : null;

            case Revision::SETTINGS:
                return $this->history->settingsOf($r->resourceId, $this->settings->all($r->locale));

            default:
                $a = $this->areas->find($r->resourceId);

                return null !== $a ? ['name' => $a->name, 'depth' => $a->depth, 'items' => $a->items[$r->locale] ?? []] : null;
        }
    }

    /** @return array<string, mixed> */
    private function present(Revision $r): array
    {
        $canView = $this->mayRevision($r, 'view');

        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'resourceId' => $r->resourceId,
            'key' => $r->resourceKey,
            'locale' => $r->locale,
            'action' => $r->action,
            'userId' => $r->userId,
            'userName' => $r->userName,
            'via' => $r->via,
            'viaName' => $r->viaName,
            'title' => $canView ? $this->visibleTitle($r) : '',
            'summary' => $canView ? $this->visibleRows($r, $r->summary) : [],
            'baseline' => $r->isBaseline(),
            'createdAt' => $r->createdAt->format(\DATE_ATOM),
            'updatedAt' => $r->updatedAt->format(\DATE_ATOM),
        ];
    }

    private function visibleTitle(Revision $r): string
    {
        if (Revision::ITEM !== $r->kind) {
            return $r->title;
        }
        $collection = $this->schema->collection($r->resourceKey);
        $field = $collection?->field($collection->titleFieldKey());
        if (null === $field || [] === $this->fields->visible([$field])) {
            return '';
        }

        // A stored title may come from a different field under an older schema.
        return $this->snapshots->title($r->kind, $r->resourceKey, $r->snapshot);
    }

    /**
     * The change rows without the fields the person may not see.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function visibleRows(Revision $r, array $rows): array
    {
        $lists = match ($r->kind) {
            Revision::PAGE => \array_map(fn (string $k): array => $this->schema->template($k)?->fieldList() ?? [], $this->keysOf($r)),
            Revision::ITEM => [$this->schema->collection($r->resourceKey)?->fieldList() ?? []],
            Revision::SETTINGS => [($this->schema->settingsGroups()[$r->resourceKey] ?? null)?->fieldList() ?? []],
            default => [[]],
        };
        $hidden = \array_flip(\array_merge(...\array_map($this->fields->hiddenKeys(...), $lists))); // hidden in any of the templates of a page
        $sections = Revision::PAGE === $r->kind ? $this->hiddenSectionFields($r) : [];
        if ([] === $hidden && [] === $sections) {
            return $rows;
        }

        return \array_values(\array_filter($rows, static function (array $row) use ($hidden, $sections): bool {
            $key = (string) ($row['key'] ?? '');
            if ('field' === ($row['kind'] ?? '')) {
                return !isset($hidden[$key]);
            }

            return !('edit' === ($row['kind'] ?? '') && isset($sections[(string) ($row['sec'] ?? '')][$key]));
        }));
    }

    /**
     * The section fields of the entry's page the person may not see: section id => { field key => true }.
     *
     * @return array<string, array<string, true>>
     */
    private function hiddenSectionFields(Revision $r): array
    {
        $byType = [];
        $out = [];
        foreach ((array) ($r->snapshot['draft']['sections'] ?? []) as $s) {
            $type = (string) ($s['type'] ?? '');
            $byType[$type] ??= \array_fill_keys($this->fields->hiddenKeys($this->schema->section($type)?->fieldList() ?? []), true);
            if ([] !== $byType[$type]) {
                $out[(string) ($s['id'] ?? '')] = $byType[$type];
            }
        }

        return $out;
    }
}

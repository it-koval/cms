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
use Itk\Cms\Entity\Role;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\ContentLocales;
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
 *
 * @internal
 */
final class HistoryAdmin
{
    public const MAX_PER_PAGE = 200;
    private readonly ContentLocales $locales;

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
        ?ContentLocales $locales = null,
    ) {
        $this->locales = $locales ?? new ContentLocales($schema->mainLocale());
    }

    /**
     * The entries of one object in one language, or all languages, newest first.
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int, keep: int, days: int}
     */
    public function entries(string $kind, string $id, ?string $locale, int $limit = 50, int $offset = 0, bool $allLocales = false): array
    {
        $locale = $allLocales ? null : ($locale ?? $this->schema->mainLocale());
        $limit = \max(1, \min(self::MAX_PER_PAGE, $limit));
        $offset = \max(0, $offset);
        $key = $this->keyOf($kind, $id);
        $this->need($kind, $key, 'view');

        $currentKey = Revision::SETTINGS === $kind && 'notifications' === $id ? Revision::NOTIFICATION_SETTINGS_KEY : null;
        $visibleKey = $this->mayReadArchivedSettings('view') ? null : $currentKey;
        $rows = $this->revisions->history($kind, $id, $locale, $limit, $offset, $visibleKey);
        $latest = $this->revisions->latestIds($kind, $id, $currentKey);
        $existing = match ($kind) {
            Revision::PAGE => $this->pageRepo->find($id)?->translations->getKeys() ?? [],
            Revision::ITEM => \array_keys($this->items->find($id)->translations ?? []),
            default => null,
        };

        return [
            'items' => \array_map(fn (Revision $r): array => $this->present($r, $key) + ['current' => !\in_array($r->action, [Revision::DELETE, Revision::DELETE_TRANSLATION], true) && (null === $existing || \in_array($r->locale, $existing, true)) && ($latest[$r->locale] ?? null) === $r->id], $rows),
            'total' => $this->revisions->countOf($kind, $id, $locale, $visibleKey),
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
        $key = $this->keyOf($r->kind, $r->resourceId);
        $this->needRevision($r, 'view', $key);
        $out = $this->present($r, $key);
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
        $now = $this->now($r);
        $latest = $this->revisions->latest($r->kind, $r->resourceId, $r->locale, Revision::SETTINGS === $r->kind && 'notifications' === $r->settingsGroup() ? Revision::NOTIFICATION_SETTINGS_KEY : null);
        $out['current'] = null !== $now && null !== $latest && $latest->id === $r->id;
        $out['canRestore'] = null !== $now && (!$this->locales->isConfigured() || $this->locales->has($r->locale)) && $this->mayRevision($r, 'edit', $key);
        if (null !== $now) {
            if (Revision::SETTINGS === $r->kind) {
                $group = $r->settingsGroup();
                $rows = $this->snapshots->diff($r->kind, $group, $this->visibleSettingsSnapshot($group, $r->snapshot), $this->visibleSettingsSnapshot($group, $now));
                $rows = $this->visibleSettingsRows($group, $rows);
                $out['against'] = $this->snapshots->cut($rows);
            } else {
                $rows = $this->snapshots->diff($r->kind, $r->resourceKey, $r->snapshot, $now);
                $out['against'] = $this->visibleRows($r, $this->snapshots->cut($rows), $key);
            }
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
        $base = ['ok' => true, 'kind' => $r->kind, 'id' => $r->resourceId, 'key' => $r->settingsGroup(), 'locale' => $r->locale];

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

        $this->locales->assertUsable($r->locale);
        if (\in_array($r->kind, [Revision::PAGE, Revision::ITEM], true) && null === $this->now($r)) {
            throw ContentException::invalid(I18n::t('content.no_version'), ['code' => 'no_translation', 'locale' => $r->locale]);
        }

        match ($r->kind) {
            Revision::PAGE => $this->pages->restoreVersion($r->resourceId, $r->locale, (array) ($r->snapshot['draft'] ?? [])),
            Revision::ITEM => $this->collections->restoreVersion($r->resourceKey, $r->resourceId, (array) ($r->snapshot['draft'] ?? []), $r->locale),
            Revision::SETTINGS => $this->restoreSettings($r),
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
        if (Revision::SETTINGS === $kind && $this->isRemovedSettingsGroup($key)) {
            if (!$this->mayReadArchivedSettings($action)) {
                throw new AccessDeniedException(I18n::t('http.no_permission'));
            }

            return;
        }
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
    private function keysOf(Revision $r, ?string $currentPageKey = null): array
    {
        if (Revision::PAGE !== $r->kind) {
            return [$r->settingsGroup()];
        }
        $now = $currentPageKey ?? $this->pageRepo->find($r->resourceId)->template ?? $this->revisions->lastOf(Revision::PAGE, $r->resourceId)->resourceKey ?? null; // a deleted page: the template it had last
        $held = $r->snapshot['template'] ?? null; // what the entry holds (an entry merged across a template change before it was split kept the older key)

        return \array_values(\array_unique(\array_filter([$r->resourceKey, $now, \is_string($held) ? $held : null], static fn (?string $k): bool => null !== $k && '' !== $k)));
    }

    private function needRevision(Revision $r, string $action, ?string $currentPageKey = null): void
    {
        if ($r->isLegacyNotificationSettings()) {
            if (!$this->mayReadArchivedSettings($action)) {
                throw new AccessDeniedException(I18n::t('http.no_permission'));
            }

            return;
        }
        foreach ($this->keysOf($r, $currentPageKey) as $key) {
            $this->need($r->kind, $key, $action);
        }
    }

    private function mayRevision(Revision $r, string $action, ?string $currentPageKey = null): bool
    {
        if ($r->isLegacyNotificationSettings()) {
            return $this->mayReadArchivedSettings($action);
        }
        foreach ($this->keysOf($r, $currentPageKey) as $key) {
            if (!$this->may($r->kind, $key, $action)) {
                return false;
            }
        }

        return true;
    }

    private function may(string $kind, string $key, string $action): bool
    {
        if (Revision::SETTINGS === $kind && $this->isRemovedSettingsGroup($key)) {
            return $this->mayReadArchivedSettings($action);
        }
        [$area, $template] = $this->area($kind, $key);
        if (Revision::ITEM === $kind && !($this->schema->collection($key)?->isVisibleTo($this->access->viewer()) ?? false)) {
            return false;
        }

        return $this->access->can($area, $action, $template);
    }

    private function isRemovedSettingsGroup(string $key): bool
    {
        return !\in_array($key, ['notifications', 'seo-files'], true) && !isset($this->schema->settingsGroups()[$key]);
    }

    private function mayReadArchivedSettings(string $action): bool
    {
        return 'view' === $action && Role::ADMIN === $this->access->user()?->role && $this->access->can('set:users', 'edit');
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

    /**
     * The version the thing has now, in the shape of the entries (null: it is not there any more).
     *
     * @return array<string, mixed>|null
     */
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
                $group = $r->settingsGroup();
                if ($r->isLegacyNotificationSettings() || $this->isRemovedSettingsGroup($group)) {
                    return null;
                }

                return $this->history->settingsOf($group, 'notifications' === $group ? $this->settings->notificationSettings() : $this->settings->all($r->locale));

            default:
                $a = $this->areas->find($r->resourceId);

                return null !== $a ? ['name' => $a->name, 'depth' => $a->depth, 'items' => $a->items[$r->locale] ?? []] : null;
        }
    }

    /** @return array<string, mixed> */
    private function present(Revision $r, ?string $currentPageKey = null): array
    {
        $canView = $this->mayRevision($r, 'view', $currentPageKey);

        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'resourceId' => $r->resourceId,
            'key' => $r->settingsGroup(),
            'locale' => $r->locale,
            'action' => $r->action,
            'userId' => $r->userId,
            'userName' => $r->userName,
            'via' => $r->via,
            'viaName' => $r->viaName,
            'title' => $canView ? $this->visibleTitle($r) : '',
            'summary' => $canView ? (Revision::SETTINGS === $r->kind ? $this->settingsSummary($r) : $this->visibleRows($r, $r->summary, $currentPageKey)) : [],
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
    private function visibleRows(Revision $r, array $rows, ?string $currentPageKey = null): array
    {
        if (Revision::SETTINGS === $r->kind) {
            return $this->visibleSettingsRows($r->resourceKey, $rows);
        }
        $lists = match ($r->kind) {
            Revision::PAGE => \array_map(fn (string $k): array => $this->schema->template($k)?->fieldList() ?? [], $this->keysOf($r, $currentPageKey)),
            Revision::ITEM => [$this->schema->collection($r->resourceKey)?->fieldList() ?? []],
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
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function visibleSettingsRows(string $group, array $rows): array
    {
        $keys = match ($group) {
            'notifications' => Schema::CORE_SETTING_KEYS,
            'seo-files' => Schema::CRAWLER_SETTING_KEYS,
            default => \array_map(static fn (FieldInterface $field): string => $field->key(), $this->fields->visible(($this->schema->settingsGroups()[$group] ?? null)?->fieldList() ?? [])),
        };
        $allowed = \array_fill_keys($keys, true);

        return \array_values(\array_filter($rows, static fn (array $row): bool => match ($row['kind'] ?? '') {
            'field' => isset($allowed[(string) ($row['key'] ?? '')]),
            'baseline' => true,
            default => false,
        }));
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function visibleSettingsSnapshot(string $group, array $snapshot): array
    {
        $snapshot = $this->history->settingsOf($group, $snapshot);
        if (\in_array($group, ['notifications', 'seo-files'], true)) {
            return $snapshot;
        }

        return $this->visibleSettingsFields(($this->schema->settingsGroups()[$group] ?? null)?->fieldList() ?? [], $snapshot);
    }

    /** @param list<FieldInterface> $fields
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function visibleSettingsFields(array $fields, array $snapshot, bool $nested = false): array
    {
        $out = $nested && \array_key_exists('_id', $snapshot) ? ['_id' => $snapshot['_id']] : [];
        $fullAdmin = $this->access->viewer()->isSystem() || (Role::ADMIN === $this->access->user()?->role && $this->access->can('set:users', 'edit'));
        foreach ($this->fields->visible($fields) as $field) {
            $key = $field->key();
            if ($field->isNote() || !\array_key_exists($key, $snapshot) || ($nested && $field->isLocked() && !$fullAdmin)) {
                continue;
            }
            if (!\in_array($field->type(), ['repeater', 'image', 'photos', 'link', 'relation'], true) && null !== $snapshot[$key] && !\is_scalar($snapshot[$key])) {
                continue; // An old compound value must not be described under today's scalar field.
            }
            $out[$key] = $snapshot[$key];
            if ('repeater' === $field->type()) {
                $out[$key] = [];
                foreach ((array) $snapshot[$key] as $row) {
                    if (\is_array($row)) {
                        $out[$key][] = $this->visibleSettingsFields($field->subFields(), $row, true);
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function settingsSummary(Revision $r): array
    {
        $group = $r->settingsGroup();
        if (\in_array($group, ['notifications', 'seo-files'], true)) {
            return $this->visibleSettingsRows($group, $r->summary);
        }
        $previous = $r->isBaseline() ? null : $this->revisions->before($r);
        if (null !== $previous && $previous->resourceKey === $r->resourceKey) {
            return $this->visibleSettingsRows($group, $this->snapshots->summary(
                $r->kind,
                $group,
                $r->action,
                $this->visibleSettingsSnapshot($group, $r->snapshot),
                $this->visibleSettingsSnapshot($group, $previous->snapshot),
            ));
        }

        // Without the previous snapshot, even a scalar field may once have been a repeater.
        // Stored descriptions cannot prove access to their old values or row titles.
        $rows = [];
        $restricted = false;
        foreach ($this->visibleSettingsRows($group, $r->summary) as $row) {
            if ('field' === ($row['kind'] ?? '')) {
                $restricted = true;
            } else {
                $rows[] = $row;
            }
        }
        if ($restricted) {
            $rows[] = ['kind' => 'meta', 'name' => '', 'label' => I18n::t('content.history_details_restricted'), 'val' => '', 'old' => ''];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function restoreSettings(Revision $r): array
    {
        $group = $r->settingsGroup();
        if ('notifications' === $group) {
            return $this->settings->updateNotifications($r->snapshot, $r->locale, Revision::RESTORE);
        }
        $snapshot = $this->history->settingsOf($group, $r->snapshot);
        $fields = \array_values(\array_filter(($this->schema->settingsGroups()[$group] ?? null)?->fieldList() ?? [], static fn (FieldInterface $field): bool => \array_key_exists($field->key(), $snapshot)));
        $patch = $this->mergeSettingsFields($fields, $snapshot, $this->settings->all($r->locale));

        return $this->settings->update($patch, $r->locale, Revision::RESTORE);
    }

    /** Restore writable leaves; protected repeater values belong to the current row identity.
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private function mergeSettingsFields(array $fields, array $snapshot, array $current): array
    {
        $writable = \array_fill_keys(\array_map(static fn (FieldInterface $field): string => $field->key(), $this->fields->writable($fields)), true);
        foreach ($fields as $field) {
            $key = $field->key();
            if (!isset($writable[$key])) {
                if (\array_key_exists($key, $current)) {
                    $snapshot[$key] = $current[$key];
                } else {
                    unset($snapshot[$key]);
                }
                continue;
            }
            if ('repeater' !== $field->type()) {
                continue;
            }
            $snapshot[$key] = \is_array($snapshot[$key] ?? null) ? $snapshot[$key] : [];
            $byId = [];
            foreach ((array) ($current[$key] ?? []) as $row) {
                if (\is_array($row) && \is_string($row['_id'] ?? null) && '' !== $row['_id']) {
                    $byId[$row['_id']] = $row;
                }
            }
            foreach ($snapshot[$key] as $index => $row) {
                if (\is_array($row)) {
                    $snapshot[$key][$index] = $this->mergeSettingsFields($field->subFields(), $row, $byId[(string) ($row['_id'] ?? '')] ?? []);
                }
            }
            $restoredIds = \array_fill_keys(\array_map(static fn ($row): string => \is_array($row) ? (string) ($row['_id'] ?? '') : '', $snapshot[$key]), true);
            foreach ((array) ($current[$key] ?? []) as $row) {
                if (\is_array($row) && (!\is_string($row['_id'] ?? null) || '' === $row['_id'] || !isset($restoredIds[$row['_id']])) && $this->hasProtectedSettingsValues($field->subFields(), $row)) {
                    $snapshot[$key][] = $row;
                }
            }
        }

        return $snapshot;
    }

    /** @param list<FieldInterface> $fields
     * @param array<string, mixed> $row
     */
    private function hasProtectedSettingsValues(array $fields, array $row): bool
    {
        $writable = \array_fill_keys(\array_map(static fn (FieldInterface $field): string => $field->key(), $this->fields->writable($fields)), true);
        foreach ($fields as $field) {
            $value = $row[$field->key()] ?? null;
            if (!isset($writable[$field->key()]) && null !== $value && '' !== $value && [] !== $value) {
                return true;
            }
            if ('repeater' === $field->type()) {
                foreach ((array) $value as $child) {
                    if (\is_array($child) && $this->hasProtectedSettingsValues($field->subFields(), $child)) {
                        return true;
                    }
                }
            }
        }

        return false;
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

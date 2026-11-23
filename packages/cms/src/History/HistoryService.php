<?php

declare(strict_types=1);

namespace Itk\Cms\History;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\MenuArea;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The write side of the history. The content services call it right before their own flush(): an entry is persisted, never flushed, so a change and its entry
 * are saved together or not at all.
 *
 * - An edit that changes nothing leaves no entry.
 * - The autosaves of one person are merged into one entry while they follow each other within ten minutes (and cancel each other out when the person undoes them).
 * - The first change to something that already existed first records the version it had before (a «baseline»), so there is something to go back to.
 * - An object keeps its newest `itk_cms.history.keep` entries; the rest and the old ones go with `itk:history:prune`.
 *
 * @internal
 */
final class HistoryService
{
    /** Edits of one person closer than this many seconds are one entry. */
    public const MERGE_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RevisionRepository $revisions,
        private readonly Snapshots $snapshots,
        private readonly Schema $schema,
        private readonly Security $security,
        private readonly ChangeSource $source,
        #[Autowire(param: 'itk_cms.history.keep')]
        private readonly int $keep,
        #[Autowire(param: 'itk_cms.history.days')]
        private readonly int $days,
    ) {
    }

    public function keep(): int
    {
        return $this->keep;
    }

    public function days(): int
    {
        return $this->days;
    }

    /* ---------- pages ---------- */

    /**
     * A page version changed (edit, publish, unpublish, revert, restore) or was born (create).
     *
     * @param array<string, mixed>|null $before the version before the change (Snapshots::page), for the baseline
     */
    public function page(Page $p, PageTranslation $t, string $action, ?array $before = null): void
    {
        $this->write(Revision::PAGE, $p->id, $p->template, $t->locale, $action, $this->snapshots->pageOf($p, $t), $before);
    }

    /** The version of a page as the history sees it (take it before the change, pass it to page() as `$before`). @return array<string, mixed> */
    public function pageState(Page $p, PageTranslation $t): array
    {
        return $this->snapshots->pageOf($p, $t);
    }

    public function pageTranslationRemoved(Page $p, PageTranslation $t): void
    {
        $snapshot = $this->snapshots->pageOf($p, $t) + [
            'published' => $t->published,
            'publishedUrl' => $t->publishedUrl,
            'publishedAt' => $t->publishedAt?->format(\DATE_ATOM),
            'updatedAt' => $t->updatedAt->format(\DATE_ATOM),
            'awaiting' => $t->awaiting,
            'returned' => $t->returned,
            'migrated' => $t->migrated,
        ];
        $this->write(Revision::PAGE, $p->id, $p->template, $t->locale, Revision::DELETE_TRANSLATION, $snapshot, null);
    }

    /** Call before the page is removed (and flushed): the whole page goes to the trash. */
    public function pageRemoved(Page $p): void
    {
        $snapshot = $this->snapshots->pageRemoved($p);
        $main = $this->schema->mainLocale();
        $locale = isset($snapshot['versions'][$main]) ? $main : (string) (\array_key_first($snapshot['versions']) ?? $main);
        $this->write(Revision::PAGE, $p->id, $p->template, $locale, Revision::DELETE, $snapshot, null);
    }

    /** A page came back from the trash: one entry for each of its language versions. */
    public function pageRestored(Page $p): void
    {
        foreach ($p->translations as $t) {
            $this->write(Revision::PAGE, $p->id, $p->template, $t->locale, Revision::RESTORE, $this->snapshots->pageOf($p, $t), null, [['kind' => 'trash', 'name' => '', 'label' => I18n::t('content.history_page_restored'), 'val' => '', 'old' => '']]);
        }
    }

    /* ---------- records of collections ---------- */

    /**
     * @param array<string, mixed>|null $before the version before the change (Snapshots::item), for the baseline
     * @param list<array<string, mixed>>|null $summary ready-made changes, instead of the computed ones
     */
    public function item(string $collection, CollectionItem $i, string $locale, string $action, ?array $before = null, ?array $summary = null): void
    {
        $t = $i->translations[$locale] ?? null;
        if (null === $t) {
            return;
        }
        $this->write(Revision::ITEM, $i->id, $collection, $locale, $action, $this->snapshots->itemOf($i, $t), $before, $summary);
    }

    /** The version of a record as the history sees it (take it before the change, pass it to item() as `$before`); null when it has no such language. @return null|array<string, mixed> */
    public function itemState(CollectionItem $i, string $locale): ?array
    {
        $t = $i->translations[$locale] ?? null;

        return null === $t ? null : $this->snapshots->itemOf($i, $t);
    }

    /** @param array<string, mixed> $t the version before it was taken off the site */
    public function itemTranslationRemoved(CollectionItem $i, string $locale, array $t): void
    {
        $snapshot = $this->snapshots->itemOf($i, $t) + $t;
        $this->write(Revision::ITEM, $i->id, $i->collection, $locale, Revision::DELETE_TRANSLATION, $snapshot, null);
    }

    /** Call before the record is removed (and flushed). */
    public function itemRemoved(CollectionItem $i): void
    {
        $snapshot = $this->snapshots->itemRemoved($i);
        $main = $this->schema->mainLocale();
        $locale = isset($i->translations[$main]) ? $main : (string) (\array_key_first($i->translations) ?? $main);
        $this->write(Revision::ITEM, $i->id, $i->collection, $locale, Revision::DELETE, $snapshot, null);
    }

    public function itemRestored(CollectionItem $i): void
    {
        foreach (\array_keys($i->translations) as $locale) {
            $this->item($i->collection, $i, (string) $locale, Revision::RESTORE, null, [['kind' => 'trash', 'name' => '', 'label' => I18n::t('content.history_item_restored'), 'val' => '', 'old' => '']]);
        }
    }

    /* ---------- settings and menus ---------- */

    /**
     * Settings were saved: one entry for each group whose values changed.
     *
     * @param array<string, mixed> $before all the settings of the language before the save
     * @param array<string, mixed> $after and after it
     * @param list<string> $keys the keys the save touched
     */
    public function settings(string $locale, array $before, array $after, array $keys, string $action = Revision::EDIT): void
    {
        $groups = $this->settingsGroupOf();
        $touched = [];
        foreach ($keys as $k) {
            if (isset($groups[$k])) {
                $touched[$groups[$k]] = true;
            }
        }
        foreach (\array_keys($touched) as $group) {
            $this->write(Revision::SETTINGS, $group, $group, $locale, $action, $this->settingsOf($group, $after), $this->settingsOf($group, $before));
        }
    }

    /** The values of one group out of all the settings of a language. @param array<string, mixed> $all @return array<string, mixed> */
    public function settingsOf(string $group, array $all): array
    {
        $own = \array_keys(\array_filter($this->settingsGroupOf(), static fn (string $g): bool => $g === $group));

        return \array_intersect_key($all, \array_flip($own));
    }

    /** @return array<string, string> setting key => key of its group (the Telegram keys belong to «notifications», the texts of robots.txt and llms.txt to «seo-files», which no settings group of a site shares with them) */
    private function settingsGroupOf(): array
    {
        $groups = [];
        foreach ($this->schema->settingsGroups() as $g) {
            foreach ($g->fieldList() as $f) {
                $groups[$f->key()] = $g->key();
            }
        }
        foreach (Schema::CORE_SETTING_KEYS as $k) {
            $groups[$k] ??= 'notifications';
        }
        foreach (Schema::CRAWLER_SETTING_KEYS as $k) {
            $groups[$k] ??= 'seo-files';
        }

        return $groups;
    }

    /**
     * The items of a menu area were saved in one language.
     *
     * @param list<array<string, mixed>> $beforeItems
     */
    public function menu(MenuArea $a, string $locale, array $beforeItems, string $action = Revision::EDIT): void
    {
        $this->write(
            Revision::MENU,
            $a->key,
            $a->key,
            $locale,
            $action,
            ['name' => $a->name, 'depth' => $a->depth, 'items' => $a->items[$locale] ?? []],
            ['name' => $a->name, 'depth' => $a->depth, 'items' => $beforeItems],
        );
    }

    /* ---------- the common part ---------- */

    /**
     * @param array<string, mixed> $snapshot the version after the change
     * @param array<string, mixed>|null $before the version before it (only the first change of an object needs it, for the baseline)
     * @param list<array<string, mixed>>|null $summary ready-made changes, instead of the computed ones
     */
    private function write(string $kind, string $id, string $key, string $locale, string $action, array $snapshot, ?array $before, ?array $summary = null): void
    {
        $edit = Revision::EDIT === $action || Revision::RESTORE === $action;
        $last = $this->revisions->latest($kind, $id, $locale);

        if ($edit && null !== $before && Json::same($before, $snapshot) && (null === $last || Json::same($last->snapshot, $snapshot))) {
            return; // nothing changed
        }
        if (null === $last && null !== $before && Revision::CREATE !== $action) {
            $last = $this->baseline($kind, $id, $key, $locale, $before);
        }
        if ($edit && null !== $last && !$last->isBaseline() && Json::same($last->snapshot, $snapshot) && Revision::EDIT === $action) {
            return;
        }

        $user = $this->security->getUser();
        $userId = $user instanceof User ? $user->id : null;

        $via = $this->source->via();
        $viaName = null === $via ? null : $this->source->name();

        // the autosaves of one person are one entry, but what an assistant did and what the person did by hand are not mixed
        if (Revision::EDIT === $action && null !== $last && Revision::EDIT === $last->action && !$last->isBaseline() && $last->userId === $userId && $last->via === $via && $last->viaName === $viaName && $last->resourceKey === $key && $this->recent($last)) { // a page that changed its template starts a new entry: an entry is checked by the template it was made under
            $prev = $this->revisions->before($last);
            if (null !== $prev && Json::same($prev->snapshot, $snapshot)) {
                $this->em->remove($last); // the person put everything back as it was

                return;
            }
            $last->snapshot = $snapshot;
            $last->title = $this->snapshots->title($kind, $key, $snapshot) ?: $last->title;
            $last->summary = $summary ?? $this->snapshots->summary($kind, $key, $action, $snapshot, $prev?->snapshot);
            $last->updatedAt = new \DateTimeImmutable();

            return;
        }

        $r = new Revision($kind, $id, $key, $locale, $action);
        $r->userId = $userId;
        $r->userName = $user instanceof User ? ('' !== $user->name ? $user->name : $user->email) : null;
        $r->via = $via;
        $r->viaName = $viaName;
        $r->title = $this->snapshots->title($kind, $key, $snapshot);
        $r->snapshot = $snapshot;
        $r->summary = $summary ?? $this->snapshots->summary($kind, $key, $action, $snapshot, $last?->snapshot);
        if (null !== $last && '' === $r->title) {
            $r->title = $last->title;
        }
        $this->trim($kind, $id, $locale);
        $this->em->persist($r);
    }

    /** The version an object had before its first recorded change. */
    private function baseline(string $kind, string $id, string $key, string $locale, array $before): Revision
    {
        if (Revision::PAGE === $kind && \is_string($before['template'] ?? null) && '' !== $before['template']) {
            $key = $before['template']; // the version before the change was made under its own template (a template change is the first recorded change)
        }
        $b = new Revision($kind, $id, $key, $locale, Revision::EDIT);
        $b->title = $this->snapshots->title($kind, $key, $before);
        $b->snapshot = $before;
        $b->summary = [['kind' => 'baseline', 'name' => '', 'label' => I18n::t('content.history_baseline'), 'val' => '', 'old' => '']];
        $this->em->persist($b);

        return $b;
    }

    private function recent(Revision $r): bool
    {
        return \time() - $r->updatedAt->getTimestamp() < self::MERGE_SECONDS;
    }

    /** Leaves room for one more entry: an object keeps its newest `keep`. */
    private function trim(string $kind, string $id, string $locale): void
    {
        $drop = $this->revisions->beyond($kind, $id, $locale, \max(1, $this->keep) - 1);
        if ([] !== $drop) {
            $this->revisions->deleteIds($drop);
        }
    }

    /**
     * Drops the entries beyond the depth of the history.
     *
     * @return array{old: int, extra: int} how many went because of their age and because an object had too many
     */
    public function prune(?int $keep = null, ?int $days = null, bool $dryRun = false): array
    {
        $keep = \max(1, $keep ?? $this->keep);
        $days = \max(1, $days ?? $this->days);
        $old = $extra = 0;

        $cutoff = new \DateTimeImmutable('-' . $days . ' days');
        while ([] !== ($ids = $this->revisions->staleIds($cutoff))) {
            $old += \count($ids);
            if ($dryRun) {
                break;
            }
            $this->revisions->deleteIds($ids);
        }
        foreach ($this->revisions->overfull($keep) as $o) {
            $ids = $this->revisions->beyond($o['kind'], $o['id'], $o['locale'], $keep);
            $extra += \count($ids);
            if (!$dryRun) {
                $this->revisions->deleteIds($ids);
            }
        }

        return ['old' => $old, 'extra' => $extra];
    }
}

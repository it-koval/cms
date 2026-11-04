<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\User;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Resource\HookDispatcher;
use Itk\Cms\Resource\Record;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records of collections: draft, publish, revert, submit, return, order.
 *
 * flat record { id, locale, translated: true, status: 'draft'|'published', updatedAt, version, ...fields, changed, changes, awaiting, returned }
 * (the tariff matrix is ONE record, id = the collection's singleton id, with cols and rows).
 */
final class CollectionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollectionItemRepository $items,
        private readonly Schema $schema,
        private readonly FieldValues $values,
        private readonly ChangeDetector $changes,
        private readonly RawFieldGuard $raw,
        private readonly HookDispatcher $hooks,
        private readonly FieldAccess $access,
        private readonly HistoryService $history,
        private readonly Security $security,
        private readonly EditLock $lock,
        private readonly DraftVersion $versions,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * Runs a read-change-write of an item with nobody else saving it meanwhile.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function locked(string $collection, string $id, \Closure $work): mixed
    {
        return $this->lock->around('item', $collection . ':' . $id, $work);
    }

    /** The core reads the section: the onLoad() hook of its resource class runs (once per request). */
    public function loaded(string $collection, ?string $locale = null): void
    {
        $this->hooks->loaded('item', $collection, $locale ?? $this->mainLocale);
    }

    public function mainLocale(): string
    {
        return $this->mainLocale;
    }

    /** @return list<array<string, mixed>> [{ key, title, singular, newLabel, icon, view, description, count, unpublished, locales }] */
    public function collections(?string $locale = null): array
    {
        $out = [];
        foreach ($this->schema->collections() as $c) {
            $rows = $this->items->findByCollection($c->key());
            $open = fn (CollectionItem $i, string $loc): bool => null !== ($t = $i->translations[$loc] ?? null) && ('draft' === ($t['status'] ?? 'draft') || $this->countChanges($c, $t) > 0);
            $count = \count($rows);
            if ('matrix' === $c->viewName()) {
                $count = isset($rows[0]) ? \count($rows[0]->translations[$this->mainLocale]['draft']['cols'] ?? []) : 0;
            }
            $unpublished = 0;
            foreach ($rows as $i) {
                if ($locale ? $open($i, $locale) : $open($i, $this->mainLocale)) {
                    ++$unpublished;
                }
            }
            $a = $c->toArray();
            $out[] = [
                'key' => $c->key(), 'title' => $c->title(), 'singular' => $a['singular'], 'newLabel' => $a['newLabel'], 'icon' => $a['icon'],
                'view' => $a['view'], 'description' => $a['description'], 'count' => $count, 'unpublished' => $unpublished,
                'locales' => [$this->mainLocale => ['count' => \count($rows), 'unpublished' => $unpublished]],
            ];
        }

        return $out;
    }

    /**
     * All records of a collection, as rows.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $collection, string $q = '', string $status = '', ?string $locale = null): array
    {
        $c = $this->collection($collection);
        $locale ??= $this->mainLocale;
        $this->ensureSingleton($c);
        $this->hooks->loaded('item', $collection, $locale);
        $needle = \mb_strtolower(\trim($q));
        $out = [];
        foreach ($this->items->findByCollection($collection) as $i) {
            $row = $this->row($c, $i, $locale);
            if ('' !== $needle) {
                $hay = \mb_strtolower((string) ($row[$c->titleFieldKey()] ?? '') . ' ' . (string) ($row[(string) ($c->toArray()['subField'] ?? '')] ?? ''));
                if (!\str_contains($hay, $needle)) {
                    continue;
                }
            }
            if ('draft' === $status && !($row['translated'] && ('draft' === $row['status'] || $row['changed']))) {
                continue;
            }
            if ('published' === $status && !($row['translated'] && 'draft' !== $row['status'] && !$row['changed'])) {
                continue;
            }
            if ('missing' === $status && $row['translated']) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return array<string, mixed> { item, published, changes, locale, translated, locales } */
    public function get(string $collection, string $id, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        $locale ??= $this->mainLocale;
        $this->ensureSingleton($c);
        $this->hooks->loaded('item', $collection, $locale);
        $i = $this->find($collection, $id);
        $t = $i->translations[$locale] ?? null;
        $live = null !== $t ? $this->live($t) : null;
        $hidden = $this->access->hiddenKeys($c->fieldList());

        return [
            'item' => null !== $t ? $this->flat($c, $i, $locale) : null,
            'published' => null !== $live ? $this->present($c, $live) : null,
            'changes' => null !== $t ? \array_values(\array_filter($this->changes->recordChanges($c, $t['draft'], $live), static fn (array $ch): bool => !\in_array($ch['field']['k'] ?? null, $hidden, true))) : [],
            'locale' => $locale,
            'translated' => null !== $t,
            'locales' => $this->summary($c, $i),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the new record (a draft, in the main language)
     */
    public function create(string $collection, array $data = [], ?string $locale = null, ?string $id = null): array
    {
        $c = $this->collection($collection);
        $locale ??= $this->mainLocale;
        if (null !== $c->singletonId() && $this->items->find($c->singletonId())) {
            throw ContentException::conflict(I18n::t('content.collection_single_edit_only'));
        }
        $parentId = $this->takeParent($c, $data);
        $data = $this->hooks->creating('item', $collection, $locale, $this->access->input($c->fieldList(), $data));
        $draft = $this->values->defaults($c->fieldList()) + $this->matrixDefaults($c);
        $draft = $this->normalized($c, $data) + $draft;
        $this->raw->assertFields($c->fieldList(), [], $draft);
        $i = new CollectionItem($collection, $id ?? $c->singletonId());
        $i->position = $this->items->nextPosition($collection);
        $i->parentId = $parentId;
        $i->translations = [$locale => ['draft' => $draft, 'published' => null, 'status' => 'draft', 'updatedAt' => Json::now()]];
        $this->em->persist($i);
        $this->history->item($collection, $i, $locale, Revision::CREATE);
        $this->em->flush();
        $this->hooks->created($this->record($c, $i, $locale));

        return $this->flat($c, $i, $locale);
    }

    /**
     * Merges `data` into that version's draft.
     * With `$baseVersion` (the `version` of the record the form loaded or saved last) the draft must still be that one, else 409 `conflict` and nothing is written;
     * the check and the write are one step under the lock of the record, on the copy read after the lock was taken. Without a version the save is not checked.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $collection, string $id, array $data, ?string $locale = null, string $action = Revision::EDIT, ?string $baseVersion = null): array
    {
        $c = $this->collection($collection);
        $locale ??= $this->mainLocale;
        if (null !== $baseVersion) {
            return $this->locked($collection, $id, function () use ($collection, $id, $data, $locale, $action, $baseVersion): array {
                $i = $this->find($collection, $id);
                $this->em->refresh($i); // the record was read before the lock: whoever held it may have saved meanwhile
                $t = $i->translations[$locale] ?? throw new ContentException(I18n::t(PageService::NO_VERSION), 404, ['code' => 'no_translation', 'locale' => $locale]);
                if (!\hash_equals($this->versionOf($i, $t), $baseVersion)) {
                    throw $this->versions->stale(Revision::ITEM, $i->id, $locale, $this->versionOf($i, $t));
                }

                return $this->update($collection, $id, $data, $locale, $action);
            });
        }
        $i = $this->find($collection, $id);
        $t = $i->translations[$locale] ?? throw new ContentException(I18n::t(PageService::NO_VERSION), 404, ['code' => 'no_translation', 'locale' => $locale]);
        $data = $this->hooks->updating($this->record($c, $i, $locale), $this->access->input($c->fieldList(), $data));
        $next = $this->normalized($c, $data) + (array) $t['draft'];
        $this->raw->assertFields($c->fieldList(), (array) $t['draft'], $next);
        $was = $this->history->itemState($i, $locale);
        $t['draft'] = $next;
        $t['updatedAt'] = Json::now();
        $this->put($i, $locale, $t);
        $this->history->item($collection, $i, $locale, $action, $was);
        $this->em->flush();
        $this->hooks->updated($this->record($c, $i, $locale));

        return $this->flat($c, $i, $locale);
    }

    /**
     * «Вернуть эту версию»: the draft of that language becomes the given one; the live copy stays as it is.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    public function restoreVersion(string $collection, string $id, array $draft, ?string $locale = null): array
    {
        return $this->update($collection, $id, $draft, $locale, Revision::RESTORE);
    }

    /**
     * A deleted record comes back from the trash (what Snapshots::itemRemoved took): every language version as a draft, the live copy kept for publishing again.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed> the flat record (main language, else the first one)
     */
    public function restoreRemoved(string $collection, string $id, array $snapshot): array
    {
        $c = $this->collection($collection);
        $versions = \array_filter((array) ($snapshot['translations'] ?? []), 'is_array');
        if ([] === $versions) {
            throw ContentException::invalid(I18n::t('content.item_nothing_left'));
        }
        if (null !== $this->items->find($id)) {
            throw ContentException::conflict(I18n::t('content.item_already_in_place'));
        }
        $i = new CollectionItem($collection, $id);
        $i->position = (int) ($snapshot['position'] ?? $this->items->nextPosition($collection));
        $parent = $c->isTree() ? (string) ($snapshot['parentId'] ?? '') : '';
        $i->parentId = '' !== $parent && null !== $this->items->find($parent) ? $parent : null; // the parent is gone: the record comes back at the top level
        if (!empty($snapshot['createdAt'])) {
            $i->createdAt = new \DateTimeImmutable((string) $snapshot['createdAt']);
        }
        foreach ($versions as $locale => $t) {
            unset($t['awaiting'], $t['returned']);
            $t['status'] = 'draft';
            $t['updatedAt'] = Json::now();
            $versions[$locale] = $t + ['draft' => [], 'published' => null];
        }
        $i->translations = $versions;
        $this->em->persist($i);
        $this->history->itemRestored($i);
        $this->em->flush();
        $locale = isset($versions[$this->mainLocale]) ? $this->mainLocale : (string) \array_key_first($versions);
        $this->hooks->created($this->record($c, $i, $locale));

        return $this->flat($c, $i, $locale);
    }

    public function remove(string $collection, string $id): void
    {
        $c = $this->collection($collection);
        $i = $this->find($collection, $id);
        $record = $this->record($c, $i, $this->mainLocale);
        $this->hooks->deleting($record);
        if ($c->isTree()) {
            $this->items->moveChildren($collection, $i->id, $i->parentId); // the records nested in it step up to its place
        }
        $this->history->itemRemoved($i);
        $this->em->remove($i);
        $this->em->flush();
        $this->hooks->deleted($record);
    }

    /**
     * Deletes several records: beforeMassDeleting once (it may cancel everything), then each record the usual way, in one transaction.
     *
     * @param list<string> $ids
     */
    public function massRemove(string $collection, array $ids): int
    {
        $c = $this->collection($collection);
        $records = $this->records($collection, $ids, $this->mainLocale);
        $this->hooks->mass('delete', 'before', 'item', $collection, $this->mainLocale, $records);
        $this->hooks->deferAfter(fn () => $this->em->wrapInTransaction(function () use ($collection, $records): void {
            foreach ($records as $r) {
                $this->remove($collection, $r->id);
            }
        }));
        $this->hooks->mass('delete', 'after', 'item', $collection, $this->mainLocale, $records);

        return \count($records);
    }

    /**
     * Publishes several records (each must pass the checks of publish()); one transaction.
     *
     * @param list<string> $ids
     */
    public function massPublish(string $collection, array $ids, ?string $locale = null): int
    {
        $locale ??= $this->mainLocale;
        $this->collection($collection);
        $records = $this->records($collection, $ids, $locale);
        $this->hooks->mass('publish', 'before', 'item', $collection, $locale, $records);
        $this->hooks->deferAfter(fn () => $this->em->wrapInTransaction(function () use ($collection, $records, $locale): void {
            foreach ($records as $r) {
                $this->publish($collection, $r->id, $locale);
            }
        }));
        $this->hooks->mass('publish', 'after', 'item', $collection, $locale, $records);

        return \count($records);
    }

    /**
     * Takes several records off the site; the ones that are not published are skipped.
     *
     * @param list<string> $ids
     */
    public function massUnpublish(string $collection, array $ids, ?string $locale = null): int
    {
        $locale ??= $this->mainLocale;
        $this->collection($collection);
        $records = $this->records($collection, $ids, $locale);
        $this->hooks->mass('unpublish', 'before', 'item', $collection, $locale, $records);
        $done = 0;
        $this->hooks->deferAfter(function () use ($collection, $records, $locale, &$done): void {
            $this->em->wrapInTransaction(function () use ($collection, $records, $locale, &$done): void {
                foreach ($records as $r) {
                    if ('published' === $r->status) {
                        $this->unpublish($collection, $r->id, $locale);
                        ++$done;
                    }
                }
            });
        });
        $this->hooks->mass('unpublish', 'after', 'item', $collection, $locale, $records);

        return $done;
    }

    /** «Снять с публикации»: the record leaves the site and becomes a draft. @return array<string, mixed> */
    public function unpublish(string $collection, string $id, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        [$i, $t, $locale] = $this->version($collection, $id, $locale);
        if (null === $this->live($t)) {
            throw ContentException::conflict(I18n::t('content.item_already_unpublished'));
        }
        $this->hooks->unpublishing($this->record($c, $i, $locale));
        $was = $this->history->itemState($i, $locale);
        $t['status'] = 'draft';
        $this->put($i, $locale, $t);
        $this->history->item($collection, $i, $locale, Revision::UNPUBLISH, $was);
        $this->em->flush();
        $this->hooks->unpublished($this->record($c, $i, $locale));

        return $this->flat($c, $i, $locale);
    }

    /**
     * The records with these ids as hooks and actions see them. A missing id is an error.
     *
     * @param list<string> $ids
     *
     * @return list<Record>
     */
    public function records(string $collection, array $ids, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        $locale ??= $this->mainLocale;
        $out = [];
        foreach (\array_values(\array_unique($ids)) as $id) {
            $out[] = $this->record($c, $this->find($collection, $id), $locale);
        }

        return $out;
    }

    public function record(Collection $c, CollectionItem $i, string $locale): Record
    {
        $t = $i->translations[$locale] ?? null;

        return new Record($i->id, 'item', $c->key(), $locale, (array) ($t['draft'] ?? []), null !== $t ? (string) ($t['status'] ?? 'draft') : 'missing', $c->isTree() ? ['parentId' => $i->parentId] : []);
    }

    /** @return array<string, mixed> */
    public function publish(string $collection, string $id, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        [$i, $t, $locale] = $this->version($collection, $id, $locale);
        $errors = 'matrix' === $c->viewName() ? [] : $this->values->validate($c->fieldList(), $t['draft']);
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('content.item_publish_required'), $errors);
        }
        $this->hooks->publishing($this->record($c, $i, $locale));
        $was = $this->history->itemState($i, $locale);
        unset($t['awaiting'], $t['returned']);
        $t['published'] = $t['draft'];
        $t['status'] = 'published';
        $t['updatedAt'] = $t['publishedAt'] = Json::now();
        $this->put($i, $locale, $t);
        $this->history->item($collection, $i, $locale, Revision::PUBLISH, $was);
        $this->em->flush();
        $this->hooks->published($this->record($c, $i, $locale));

        return $this->flat($c, $i, $locale);
    }

    /** @return array<string, mixed> */
    public function revert(string $collection, string $id, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        [$i, $t, $locale] = $this->version($collection, $id, $locale);
        if (null === $this->live($t)) {
            throw ContentException::conflict(I18n::t('content.item_never_published'));
        }
        $was = $this->history->itemState($i, $locale);
        unset($t['awaiting'], $t['returned']);
        $t['draft'] = $t['published'];
        $t['updatedAt'] = Json::now();
        $this->put($i, $locale, $t);
        $this->history->item($collection, $i, $locale, Revision::REVERT, $was);
        $this->em->flush();

        return $this->flat($c, $i, $locale);
    }

    /** @return array<string, mixed> */
    public function submit(string $collection, string $id, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        [$i, $t, $locale] = $this->version($collection, $id, $locale);
        $errors = 'matrix' === $c->viewName() ? [] : $this->values->validate($c->fieldList(), $t['draft']);
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('content.item_submit_required'), $errors);
        }
        unset($t['returned']);
        $t['awaiting'] = ['by' => $this->actor(), 'at' => Json::now()];
        $this->put($i, $locale, $t);
        $this->em->flush();

        return $this->flat($c, $i, $locale);
    }

    /** @return array<string, mixed> */
    public function returnToAuthor(string $collection, string $id, ?string $locale = null): array
    {
        $c = $this->collection($collection);
        [$i, $t, $locale] = $this->version($collection, $id, $locale);
        unset($t['awaiting']);
        $t['returned'] = ['by' => $this->actor(), 'at' => Json::now()];
        $this->put($i, $locale, $t);
        $this->em->flush();

        return $this->flat($c, $i, $locale);
    }

    /** @param list<string> $ids ALL record ids in the new order (sortable collections only) */
    public function reorder(string $collection, array $ids): void
    {
        $c = $this->collection($collection);
        if (!$c->isSortable()) {
            throw ContentException::invalid(I18n::t('content.collection_order_locked'));
        }
        $byId = [];
        foreach ($this->items->findByCollection($collection) as $i) {
            $byId[$i->id] = $i;
        }
        foreach (\array_values($ids) as $pos => $id) {
            if (isset($byId[$id])) {
                $byId[$id]->position = $pos;
            }
        }
        $this->em->flush();
    }

    /**
     * Moves records inside a collection with tree(): each node is { id, parentId, position } (position = place among the records of the same parent).
     * Records that are not named stay where they are. A record cannot go into itself or into its own branch. A record that gets another parent goes
     * through the hooks of an update (beforeUpdating with ['parentId' => …], afterUpdated); all of it is one transaction.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return int how many records changed their parent
     */
    public function tree(string $collection, array $nodes): int
    {
        $c = $this->collection($collection);
        if (!$c->isTree()) {
            throw ContentException::invalid(I18n::t('content.collection_not_nested'));
        }
        $byId = [];
        $current = [];
        foreach ($this->items->findByCollection($collection) as $i) {
            $byId[$i->id] = $i;
            $current[$i->id] = [$i->parentId, $i->position];
        }
        $want = [];
        foreach ($nodes as $n) {
            $id = (string) ($n['id'] ?? '');
            if (isset($want[$id])) {
                throw ContentException::invalid(I18n::t('content.item_named_twice'));
            }
            $want[$id] = [isset($n['parentId']) && '' !== $n['parentId'] ? (string) $n['parentId'] : null, \max(0, (int) ($n['position'] ?? 0))];
        }
        $plan = TreeMove::resolve($current, $want);
        $moved = $plan['moved'];
        $was = [];
        foreach ($moved as $id) {
            $this->hooks->updating($this->record($c, $byId[$id], $this->mainLocale), ['parentId' => $plan['parent'][$id]]);
            foreach (\array_keys($byId[$id]->translations) as $locale) {
                $was[$id][$locale] = $this->history->itemState($byId[$id], (string) $locale);
            }
        }
        $this->em->wrapInTransaction(function () use ($collection, $plan, $byId, $was): void {
            foreach ($plan['parent'] as $id => $parent) {
                $item = $byId[$id];
                $item->parentId = $parent;
                $item->position = $plan['position'][$id];
            }
            // the place is shared by all languages: every version says who moved the record (the check of an open form quotes it)
            foreach ($was as $id => $versions) {
                foreach ($versions as $locale => $state) {
                    $this->history->item($collection, $byId[$id], (string) $locale, Revision::EDIT, $state);
                }
            }
        });
        foreach ($moved as $id) {
            $this->hooks->updated($this->record($c, $byId[$id], $this->mainLocale));
        }

        return \count($moved);
    }

    /**
     * Published records of a language in site order, for templates: [{ id, ...fields }].
     *
     * @return list<array<string, mixed>>
     */
    public function published(string $collection, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $out = [];
        foreach ($this->items->findByCollection($collection) as $i) {
            $t = $i->translations[$locale] ?? null;
            $live = null !== $t ? $this->live($t) : null;
            if (null !== $live) {
                $out[] = ['id' => $i->id] + $live;
            }
        }

        return $out;
    }

    /* ---------- shapes ---------- */

    /** @return array<string, mixed> */
    public function flat(Collection $c, CollectionItem $i, string $locale): array
    {
        $t = $i->translations[$locale];
        $n = $this->countChanges($c, $t);

        return [
            'id' => $i->id,
            'locale' => $locale,
            'translated' => true,
            'status' => $t['status'] ?? 'draft',
            'updatedAt' => $t['updatedAt'] ?? null,
            'version' => $this->versionOf($i, $t),
            'awaiting' => $t['awaiting'] ?? null,
            'returned' => $t['returned'] ?? null,
        ] + $this->present($c, (array) $t['draft']) + ($c->isTree() ? ['parentId' => $i->parentId] : []) + ['changed' => $n > 0, 'changes' => $n];
    }

    /**
     * What the form sends back as `baseVersion`: it changes when the draft (or the place in the tree) changes, not with publishing.
     *
     * @param array<string, mixed> $t one language version
     */
    public function versionOf(CollectionItem $i, array $t): string
    {
        return $this->versions->of(['draft' => $t['draft'] ?? [], 'parentId' => $i->parentId]);
    }

    /** @return array<string, mixed> */
    public function row(Collection $c, CollectionItem $i, string $locale): array
    {
        $t = $i->translations[$locale] ?? null;
        $src = $t ?? ($i->translations[$this->mainLocale] ?? \reset($i->translations) ?: ['draft' => []]);
        $n = null !== $t ? $this->countChanges($c, $t) : 0;

        return $this->present($c, (array) $src['draft']) + ($c->isTree() ? ['parentId' => $i->parentId] : []) + [
            'id' => $i->id,
            'position' => $i->position,
            'locale' => $locale,
            'translated' => null !== $t,
            'status' => null !== $t ? ($t['status'] ?? 'draft') : 'missing',
            'updatedAt' => $t['updatedAt'] ?? null,
            'awaiting' => $t['awaiting'] ?? null,
            'returned' => $t['returned'] ?? null,
            'changed' => $n > 0,
            'changes' => $n,
            'locales' => $this->summary($c, $i),
        ];
    }

    /** @return array<string, array<string, mixed>|null> */
    public function summary(Collection $c, CollectionItem $i): array
    {
        $out = [$this->mainLocale => null];
        foreach ($i->translations as $locale => $t) {
            $n = $this->countChanges($c, $t);
            $out[$locale] = ['status' => $t['status'] ?? 'draft', 'changed' => $n > 0, 'changes' => $n, 'title' => $t['draft'][$c->titleFieldKey()] ?? '', 'updatedAt' => $t['updatedAt'] ?? null, 'awaiting' => $t['awaiting'] ?? null];
        }

        return $out;
    }

    /* ---------- internals ---------- */

    private function collection(string $key): Collection
    {
        return $this->schema->collection($key) ?? throw ContentException::notFound(I18n::t('content.collection_not_found'));
    }

    /**
     * The parent of a new record of a tree collection (`parentId` of the data; taken out of it). A collection without a tree has no parents.
     *
     * @param array<string, mixed> $data
     */
    private function takeParent(Collection $c, array &$data): ?string
    {
        $parent = $data['parentId'] ?? null;
        if (!$c->isTree()) {
            return null;
        }
        unset($data['parentId']);
        if (null === $parent || '' === $parent) {
            return null;
        }
        $this->find($c->key(), (string) $parent);

        return (string) $parent;
    }

    private function find(string $collection, string $id): CollectionItem
    {
        $i = $this->items->find($id);

        return null !== $i && $i->collection === $collection ? $i : throw ContentException::notFound(I18n::t('content.item_not_found'));
    }

    /** @return array{0: CollectionItem, 1: array<string, mixed>, 2: string} */
    private function version(string $collection, string $id, ?string $locale): array
    {
        $locale ??= $this->mainLocale;
        $i = $this->find($collection, $id);
        $t = $i->translations[$locale] ?? throw new ContentException(I18n::t(PageService::NO_VERSION), 404, ['code' => 'no_translation', 'locale' => $locale]);

        return [$i, $t, $locale];
    }

    /** @param array<string, mixed> $t */
    private function put(CollectionItem $i, string $locale, array $t): void
    {
        $all = $i->translations;
        $all[$locale] = $t;
        $i->translations = $all; // reassign: Doctrine tracks the JSON column by value
    }

    /** @param array<string, mixed> $t @return null|array<string, mixed> */
    private function live(array $t): ?array
    {
        return 'published' === ($t['status'] ?? '') && \is_array($t['published'] ?? null) ? $t['published'] : null;
    }

    /** @param array<string, mixed> $t */
    private function countChanges(Collection $c, array $t): int
    {
        $live = $this->live($t);

        return null === $live ? 0 : \count($this->changes->recordChanges($c, (array) $t['draft'], $live));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalized(Collection $c, array $data): array
    {
        $out = $this->values->normalize($c->fieldList(), $data);
        if ('matrix' === $c->viewName()) {
            foreach (['cols', 'rows'] as $k) {
                if (isset($data[$k]) && \is_array($data[$k])) {
                    $out[$k] = \array_values($data[$k]);
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function matrixDefaults(Collection $c): array
    {
        return 'matrix' === $c->viewName() ? ['cols' => [], 'rows' => []] : [];
    }

    /** The only record of a singleton collection (the tariff matrix) exists from the first read. */
    private function ensureSingleton(Collection $c): void
    {
        if (null !== $c->singletonId() && null === $this->items->find($c->singletonId())) {
            $this->create($c->key(), [], null, $c->singletonId());
        }
    }

    /**
     * The values the current person may see.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    private function present(Collection $c, array $draft): array
    {
        return $this->access->output($c->fieldList(), $draft);
    }

    private function actor(): string
    {
        $u = $this->security->getUser();

        return $u instanceof User && '' !== $u->name ? $u->name : I18n::t('content.actor_default');
    }
}

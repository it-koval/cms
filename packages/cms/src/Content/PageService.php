<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\User;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Resource\HookDispatcher;
use Itk\Cms\Resource\Record;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\Template;
use Itk\Cms\Support\Json;
use Itk\Cms\Support\Url;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pages: draft save, publish, revert, unpublish, submit for publication, return to author, listing.
 *
 * Page shapes (same as admin/src/api/pages.js):
 *   flat page   { id, parentId, template, locale, translated: true, title, url, seo, sections, fields, status, updatedAt, version, migrated, awaiting?, returned? }
 *   ROW         flat page of the list without sections: + sectionsCount, changed, changes, published, locales: { ru: {…}|null }
 *   draft view  { page, published, changes, locale, translated, locales, shell }
 * All problems are ContentException (404 / 409 / 422 with a plain message in the language of the person).
 */
final class PageService
{
    /** The key of the text of the 404 for a language the page has no version in: I18n::t(PageService::NO_VERSION). */
    public const NO_VERSION = 'content.no_version';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PageRepository $pages,
        private readonly PageTranslationRepository $translations,
        private readonly Schema $schema,
        private readonly FieldValues $values,
        private readonly HtmlSanitizer $html,
        private readonly ChangeDetector $changes,
        private readonly H1Rule $h1,
        private readonly Placement $placement,
        private readonly RedirectService $redirects,
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
     * Runs a read-change-write of a page with nobody else saving it meanwhile (the tools of the assistants use it).
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function locked(string $id, \Closure $work): mixed
    {
        return $this->lock->around('page', $id, $work);
    }

    public function mainLocale(): string
    {
        return $this->mainLocale;
    }

    /* ---------- reading ---------- */

    public function find(string $id): Page
    {
        return $this->pages->find($id) ?? throw ContentException::notFound(I18n::t('content.page_not_found'));
    }

    /**
     * All pages (without sections); `locale` decides which language the top-level fields describe.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $locale = null, ?string $template = null, ?string $parentId = null): array
    {
        $locale ??= $this->mainLocale;
        if (null !== $template) {
            $this->hooks->loaded('page', $template, $locale);
        }
        $out = [];
        foreach ($this->pages->findAllOrdered() as $p) {
            if (null !== $template && $p->template !== $template) {
                continue;
            }
            if (null !== $parentId && $p->parentId() !== $parentId) {
                continue;
            }
            $out[] = $this->row($p, $locale);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function get(string $id, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $p = $this->find($id);
        $t = $p->translation($locale) ?? throw new ContentException(I18n::t(self::NO_VERSION), 404, ['code' => 'no_translation', 'locale' => $locale]);
        $this->hooks->loaded('page', $p->template, $locale);

        return $this->flat($p, $t);
    }

    /** @return array<string, mixed>|null the snapshot on the live site, or null */
    public function getPublished(string $id, ?string $locale = null): ?array
    {
        $t = $this->find($id)->translation($locale ?? $this->mainLocale);

        return null !== $t ? $this->liveSnapshot($t) : null;
    }

    /** @return array<string, mixed> what the page editor loads */
    public function getDraft(string $id, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $p = $this->find($id);
        $t = $p->translation($locale);
        $page = null !== $t ? $this->flat($p, $t) : null;
        $published = null !== $t ? $this->liveSnapshot($t) : null;
        $this->hooks->loaded('page', $p->template, $locale);
        $hidden = $this->hiddenFields($p->template);

        return [
            'page' => $page,
            'published' => null !== $published ? $this->hide($this->present($published), $p->template) : null,
            'changes' => null !== $page ? $this->visibleChanges($this->changes->pageChanges($t->draft, $p->template, $published), $t->draft, $hidden) : [],
            'locale' => $locale,
            'translated' => null !== $t,
            'locales' => $this->summary($p),
            'shell' => ['id' => $p->id, 'parentId' => $p->parentId(), 'template' => $p->template],
        ];
    }

    /** @return array<string, mixed>|null the live snapshot (null while the version is a draft or unpublished) */
    public function liveSnapshot(PageTranslation $t): ?array
    {
        return $t->isLive() ? $t->published : null;
    }

    /** Number of unpublished changes (0 when the version was never published). */
    public function changeCount(PageTranslation $t): int
    {
        $live = $this->liveSnapshot($t);

        return null === $live ? 0 : \count($this->changes->pageChanges($t->draft, $t->page->template, $live));
    }

    /** @return list<array<string, mixed>> */
    public function changesOf(PageTranslation $t): array
    {
        return $this->changes->pageChanges($t->draft, $t->page->template, $this->liveSnapshot($t));
    }

    /** The page's one H1 id (section id or null). */
    public function h1Id(array $draftOrSnapshot, string $template): ?string
    {
        return $this->h1->pageH1Id($draftOrSnapshot['sections'] ?? [], $this->schema->template($template));
    }

    /** Required fields that block publishing: sections' ones { sectionId, path, field, message } and the template's own { sectionId: null, scope: 'fields', … }. */
    public function requiredErrors(array $draft, string $template): array
    {
        $out = [];
        foreach ($draft['sections'] ?? [] as $s) {
            $fields = $this->schema->sectionFields((string) $s['type']);
            foreach ($this->values->validate($fields, $s['data'] ?? []) as $e) {
                $out[] = ['sectionId' => $s['id']] + $e;
            }
        }
        $tpl = $this->schema->template($template);
        if (null !== $tpl) {
            foreach ($this->values->validate($tpl->fieldList(), $draft['fields'] ?? []) as $e) {
                $out[] = ['sectionId' => null, 'scope' => 'fields'] + $e;
            }
        }

        return $out;
    }

    /* ---------- writing ---------- */

    /**
     * New page, born in the main language as a draft.
     *
     * @param array{title?: string, url?: string, parentId?: ?string, template?: string, sections?: ?array, fields?: ?array, id?: ?string, locale?: string, seo?: array} $input
     *
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $templateKey = (string) ($input['template'] ?? 'default');
        $locale = (string) ($input['locale'] ?? $this->mainLocale);
        if (null !== ($known = $this->schema->template($templateKey))) {
            $input['fields'] = $this->access->input($known->fieldList(), (array) ($input['fields'] ?? []));
            if (\is_array($input['sections'] ?? null)) {
                $input['sections'] = $this->guardSections($input['sections'], []);
            }
            $input = $this->hooks->creating('page', $templateKey, $locale, $input) + ['template' => $templateKey, 'locale' => $locale];
        }
        $title = \trim((string) ($input['title'] ?? ''));
        $url = \trim((string) ($input['url'] ?? ''));
        $parentId = $input['parentId'] ?? null;
        if ('' === $title) {
            throw ContentException::invalid(I18n::t('content.page_title_required'));
        }
        if (!\str_starts_with($url, '/')) {
            throw ContentException::invalid(I18n::t('content.page_url_slash'));
        }
        $tpl = $this->schema->template($templateKey) ?? throw ContentException::invalid(I18n::t('content.page_template_unknown'));
        self::assertNotReserved($url);
        if ($this->urlTaken($locale, $url, null)) {
            throw ContentException::conflict(I18n::t('content.page_url_exists'));
        }
        $parent = null;
        if ($parentId) {
            $parent = $this->pages->find($parentId) ?? throw ContentException::invalid(I18n::t('content.page_parent_missing'));
        }
        $rows = $this->guardRows();
        $place = $this->placement->canPlace($templateKey, null !== $parent ? ['id' => $parent->id, 'template' => $parent->template] : null, $rows);
        if (!$place['ok']) {
            throw ContentException::invalid($place['reason']);
        }

        $fields = $this->values->normalize($tpl->fieldList(), (array) ($input['fields'] ?? []));
        $fields += $this->values->defaults($tpl->fieldList(), true);
        $nameKey = $this->nameKey($tpl);
        if (null !== $nameKey && '' === \trim((string) ($fields[$nameKey] ?? ''))) {
            $fields[$nameKey] = $this->titleAsField($tpl->field($nameKey), $title);
        }
        $sections = $input['sections'] ?? null;
        $sections = !$tpl->hasSections() || (null !== $tpl->h1Field() && !$sections) ? [] : $this->normalizeSections($sections ?: $this->defaultSections($title));

        $this->raw->assertFields($tpl->fieldList(), [], $fields);
        $this->raw->assertSections([], $sections);

        $page = new Page($templateKey, $parent, $input['id'] ?? null);
        $page->position = $this->pages->nextPosition($parent);
        $t = new PageTranslation($page, $locale);
        $t->draft = [
            'title' => $title,
            'url' => $url,
            'seo' => (array) ($input['seo'] ?? []) + ['title' => $title, 'description' => ''],
            'sections' => $sections,
            'fields' => $fields,
        ];
        $page->translations->set($locale, $t);
        $this->em->persist($page);
        $this->history->page($page, $t, Revision::CREATE);
        $this->em->flush();
        $this->hooks->created($this->record($page, $t));

        return $this->flat($page, $t);
    }

    /**
     * Autosave of the editor: title, url, seo, sections, fields, parentId, template (the last two are shared by all languages).
     * With `$baseVersion` (the `version` of the page the editor loaded or saved last) the draft must still be that one, else 409 `conflict` and nothing is written.
     * The check and the write are one step under the lock of the page, on the copy read after the lock was taken. Without a version the save is not checked.
     *
     * @return array<string, mixed> flat page + changed
     */
    public function saveDraft(string $id, ?string $locale, array $data, ?string $baseVersion = null): array
    {
        $locale ??= $this->mainLocale;
        $flat = null === $baseVersion
            ? $this->writeVersion($id, $locale, $data, true)
            : $this->locked($id, function () use ($id, $locale, $data, $baseVersion): array {
                $p = $this->find($id);
                $t = $this->version($p, $locale);
                $this->em->refresh($p); // the page was read before the lock: whoever held it may have saved meanwhile
                $this->em->refresh($t);
                if (!\hash_equals($this->versionOf($p, $t), $baseVersion)) {
                    throw $this->versions->stale(Revision::PAGE, $p->id, $locale, $this->versionOf($p, $t));
                }

                return $this->writeVersion($id, $locale, $data, true);
            });
        $t = $this->find($id)->translation($locale);

        return $flat + ['changed' => $this->changeCount($t) > 0];
    }

    /**
     * List-level edit: title, url, seo, fields of that language + parentId, template (shared). No sections.
     *
     * @return array<string, mixed>
     */
    public function update(string $id, ?string $locale, array $patch): array
    {
        return $this->writeVersion($id, $locale ?? $this->mainLocale, $patch, false);
    }

    /**
     * «Вернуть эту версию»: the draft of that language becomes the given one (title, address, SEO, sections, fields); the live copy stays as it is.
     * The place in the tree and the template are not touched: they are shared by all languages and may have moved on since.
     *
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed> the flat page
     */
    public function restoreVersion(string $id, ?string $locale, array $draft): array
    {
        $data = ['title' => $draft['title'] ?? '', 'url' => $draft['url'] ?? '', 'seo' => (array) ($draft['seo'] ?? []), 'sections' => (array) ($draft['sections'] ?? []), 'fields' => (array) ($draft['fields'] ?? [])];

        return $this->writeVersion($id, $locale ?? $this->mainLocale, $data, true, Revision::RESTORE);
    }

    /**
     * A deleted page comes back from the trash (what Snapshots::pageRemoved took): every language version as a draft, the live copy kept for publishing again.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed> the flat page (main language, else the first one)
     */
    public function restoreRemoved(array $snapshot): array
    {
        $meta = (array) ($snapshot['page'] ?? []);
        $versions = (array) ($snapshot['versions'] ?? []);
        $id = (string) ($meta['id'] ?? '');
        if ('' === $id || [] === $versions) {
            throw ContentException::invalid(I18n::t('content.page_nothing_left'));
        }
        if (null !== $this->pages->find($id)) {
            throw ContentException::conflict(I18n::t('content.page_already_in_place'));
        }
        $template = (string) ($meta['template'] ?? 'default');
        if (null === $this->schema->template($template)) {
            throw ContentException::invalid(I18n::t('content.page_template_gone'));
        }
        $parent = null !== ($meta['parentId'] ?? null) ? $this->pages->find((string) $meta['parentId']) : null; // the parent is gone: the page comes back at the top level
        $place = $this->placement->canPlace($template, null !== $parent ? ['id' => $parent->id, 'template' => $parent->template] : null, $this->guardRows());
        if (!$place['ok']) {
            throw ContentException::invalid($place['reason']);
        }
        foreach ($versions as $locale => $v) {
            $url = (string) ($v['draft']['url'] ?? '');
            if ($this->urlTaken((string) $locale, $url, null) || $this->urlTaken((string) $locale, (string) ($v['published']['url'] ?? ''), null)) {
                throw ContentException::conflict(I18n::t('content.page_url_taken_restore', ['url' => $url]));
            }
        }

        $page = new Page($template, $parent, $id);
        $page->position = (int) ($meta['position'] ?? $this->pages->nextPosition($parent));
        if (!empty($meta['createdAt'])) {
            $page->createdAt = new \DateTimeImmutable((string) $meta['createdAt']);
        }
        foreach ($versions as $locale => $v) {
            $t = new PageTranslation($page, (string) $locale);
            $t->draft = (array) ($v['draft'] ?? []);
            $t->published = \is_array($v['published'] ?? null) ? $v['published'] : null;
            $t->publishedAt = !empty($v['publishedAt']) ? new \DateTimeImmutable((string) $v['publishedAt']) : null;
            $page->translations->set((string) $locale, $t);
        }
        $this->em->persist($page);
        $this->history->pageRestored($page);
        $this->em->flush();
        $main = $page->translation($this->mainLocale) ?? $page->translations->first();
        $this->hooks->created($this->record($page, $main));

        return $this->flat($page, $main);
    }

    /**
     * @param list<string> $ids ALL sibling page ids in the new order
     * @param callable(Page): void|null $guard called for every page whose place really changes (the controller checks the right to edit its template); nothing is written before all of them passed
     */
    public function reorder(array $ids, ?callable $guard = null): void
    {
        $pages = [];
        foreach (\array_values($ids) as $i => $pid) {
            $pages[$i] = $this->find($pid);
        }
        if (null !== $guard) {
            foreach ($pages as $i => $p) {
                if ($p->position !== $i) {
                    $guard($p);
                }
            }
        }
        foreach ($pages as $i => $p) {
            $p->position = $i;
        }
        $this->em->flush();
    }

    /**
     * The drag-and-drop of «Страницы»: moves pages in the site tree. Each node is { id, parentId, position } (position = place among the pages of the same
     * parent); pages that are not named stay where they are. A page cannot go into itself or into its own branch, the home page stays at the top, and a page
     * goes only where its template may stand (the same rules as «Настройки страницы»). A page that gets another parent goes through the hooks of an update.
     * `$guard` is called for every page that really moves (the controller checks the right to edit its template); nothing changes before all of them passed.
     *
     * @param list<array<string, mixed>> $nodes
     * @param callable(Page): void|null $guard
     *
     * @return int how many pages changed their parent
     */
    public function tree(array $nodes, ?callable $guard = null): int
    {
        $byId = [];
        $current = [];
        foreach ($this->pages->findAllOrdered() as $p) {
            $byId[$p->id] = $p;
            $current[$p->id] = [$p->parentId(), $p->position];
        }
        $want = [];
        foreach ($nodes as $n) {
            $id = (string) ($n['id'] ?? '');
            if (isset($want[$id])) {
                throw ContentException::invalid(I18n::t('content.page_named_twice'));
            }
            $want[$id] = [isset($n['parentId']) && '' !== $n['parentId'] ? (string) $n['parentId'] : null, \max(0, (int) ($n['position'] ?? 0))];
        }
        $plan = TreeMove::resolve($current, $want);
        $rows = $this->guardRows();
        $moved = $plan['moved'];
        foreach ($moved as $id) {
            $p = $byId[$id];
            $parent = $plan['parent'][$id];
            if ($this->isRoot($p)) {
                throw ContentException::invalid(I18n::t('content.page_home_top_level'));
            }
            $r = $this->placement->canPlace($p->template, null !== $parent ? ['id' => $parent, 'template' => $byId[$parent]->template] : null, $rows, $p->id);
            if (!$r['ok']) {
                throw ContentException::invalid($r['reason']);
            }
        }
        if (null !== $guard) {
            foreach ([...$moved, ...$plan['reordered']] as $id) {
                $guard($byId[$id]);
            }
        }
        $was = [];
        foreach ($moved as $id) {
            $this->hooks->updating($this->recordOf($byId[$id]), ['parentId' => $plan['parent'][$id]]);
            foreach ($byId[$id]->translations as $t) {
                $was[$id][$t->locale] = $this->history->pageState($byId[$id], $t);
            }
        }
        $this->em->wrapInTransaction(function () use ($plan, $byId, $was): void {
            foreach ($plan['parent'] as $id => $parent) {
                $page = $byId[$id];
                $page->parent = null !== $parent ? $byId[$parent] : null;
                $page->position = $plan['position'][$id];
            }
            // the place is shared by all languages: every version says who moved the page (the check of an open editor quotes it)
            foreach ($was as $id => $versions) {
                foreach ($versions as $locale => $state) {
                    $this->history->page($byId[$id], $byId[$id]->translation((string) $locale), Revision::EDIT, $state);
                }
            }
        });
        foreach ($moved as $id) {
            $this->hooks->updated($this->recordOf($byId[$id]));
        }

        return \count($moved);
    }

    public function remove(string $id): void
    {
        $p = $this->find($id);
        if ($this->pages->countChildren($p) > 0) {
            throw ContentException::conflict(I18n::t('content.page_delete_children_first'));
        }
        if ($this->isRoot($p)) {
            throw ContentException::conflict(I18n::t('content.page_home_not_deletable'));
        }
        $record = $this->recordOf($p);
        $this->hooks->deleting($record);
        $this->history->pageRemoved($p);
        $this->em->remove($p);
        $this->em->flush();
        $this->hooks->deleted($record);
    }

    /**
     * Deletes several pages: beforeMassDeleting once (per template; it may cancel everything), then each page the usual way, in one transaction.
     *
     * @param list<string> $ids
     */
    public function massRemove(array $ids): int
    {
        $records = $this->records($ids);
        foreach ($this->byTemplate($records) as $template => $group) {
            $this->hooks->mass('delete', 'before', 'page', $template, $this->mainLocale, $group);
        }
        $this->hooks->deferAfter(fn () => $this->em->wrapInTransaction(function () use ($records): void {
            foreach ($records as $r) {
                $this->remove($r->id);
            }
        }));
        foreach ($this->byTemplate($records) as $template => $group) {
            $this->hooks->mass('delete', 'after', 'page', $template, $this->mainLocale, $group);
        }

        return \count($records);
    }

    /**
     * Publishes several pages (each must pass the checks of publish()), in one transaction.
     *
     * @param list<string> $ids
     */
    public function massPublish(array $ids, ?string $locale = null): int
    {
        $locale ??= $this->mainLocale;
        $records = $this->records($ids, $locale);
        foreach ($this->byTemplate($records) as $template => $group) {
            $this->hooks->mass('publish', 'before', 'page', $template, $locale, $group);
        }
        $this->hooks->deferAfter(fn () => $this->em->wrapInTransaction(function () use ($records, $locale): void {
            foreach ($records as $r) {
                $this->publish($r->id, $locale);
            }
        }));
        foreach ($this->byTemplate($records) as $template => $group) {
            $this->hooks->mass('publish', 'after', 'page', $template, $locale, $group);
        }

        return \count($records);
    }

    /**
     * The pages with these ids as hooks and actions see them. A missing id is an error.
     *
     * @param list<string> $ids
     *
     * @return list<Record>
     */
    public function records(array $ids, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $out = [];
        foreach (\array_values(\array_unique($ids)) as $id) {
            $p = $this->find($id);
            $out[] = $this->record($p, $this->version($p, $locale));
        }

        return $out;
    }

    public function record(Page $p, PageTranslation $t): Record
    {
        $d = $t->draft;

        return new Record($p->id, 'page', $p->template, $t->locale, (array) ($d['fields'] ?? []), $t->status, [
            'title' => (string) ($d['title'] ?? ''),
            'url' => (string) ($d['url'] ?? ''),
            'seo' => (array) ($d['seo'] ?? []),
            'parentId' => $p->parentId(),
            'template' => $p->template,
        ]);
    }

    /** @return array<string, mixed> */
    public function publish(string $id, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $p = $this->find($id);
        $t = $this->version($p, $locale);
        $errors = $this->requiredErrors($t->draft, $p->template);
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('content.page_publish_required'), $errors);
        }
        $url = Url::key((string) ($t->draft['url'] ?? ''));
        if ('' === $url) {
            throw ContentException::invalid(I18n::t('content.page_publish_url_required'));
        }
        foreach ($this->translations->findForLocale($locale) as $other) {
            if ($other->id !== $t->id && $other->publishedUrl === $url) {
                throw ContentException::conflict(I18n::t('content.page_url_taken_published'));
            }
        }
        $this->hooks->publishing($this->record($p, $t));
        $was = $this->history->pageState($p, $t);
        $before = $t->isLive() ? (string) ($t->published['url'] ?? '') : null;
        $t->published = $this->snapshot($t->draft);
        $t->status = PageTranslation::PUBLISHED;
        $t->publishedUrl = $url;
        $t->awaiting = null;
        $t->returned = null;
        $t->migrated = false;
        $t->updatedAt = $t->publishedAt = new \DateTimeImmutable();
        if (null !== $before) {
            $this->redirects->moved($before, (string) $t->published['url']);
        }
        $this->history->page($p, $t, Revision::PUBLISH, $was);
        $this->em->flush();
        $this->hooks->published($this->record($p, $t));

        return $this->flat($p, $t);
    }

    /** Throws away unpublished changes of that language. */
    public function revert(string $id, ?string $locale = null): array
    {
        $p = $this->find($id);
        $t = $this->version($p, $locale ?? $this->mainLocale);
        if (null === $t->published) {
            throw ContentException::conflict(I18n::t('content.page_never_published'));
        }
        $was = $this->history->pageState($p, $t);
        $t->draft = $t->published;
        $t->awaiting = null;
        $t->returned = null;
        $t->updatedAt = new \DateTimeImmutable();
        $this->history->page($p, $t, Revision::REVERT, $was);
        $this->em->flush();

        return $this->flat($p, $t);
    }

    /** «Отправить на публикацию» for a person who may edit but not publish. */
    public function submit(string $id, ?string $locale = null): array
    {
        $p = $this->find($id);
        $t = $this->version($p, $locale ?? $this->mainLocale);
        $errors = $this->requiredErrors($t->draft, $p->template);
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('content.page_submit_required'), $errors);
        }
        $t->returned = null;
        $t->awaiting = ['by' => $this->actor(), 'at' => Json::now()];
        $this->em->flush();

        return $this->flat($p, $t);
    }

    /** «Вернуть автору»: the version stops waiting; its text stays as the author left it. */
    public function returnToAuthor(string $id, ?string $locale = null): array
    {
        $p = $this->find($id);
        $t = $this->version($p, $locale ?? $this->mainLocale);
        $t->awaiting = null;
        $t->returned = ['by' => $this->actor(), 'at' => Json::now()];
        $this->em->flush();

        return $this->flat($p, $t);
    }

    /** «Снять с публикации»: the version leaves the site and becomes a draft; the live copy is kept for republish(). */
    public function unpublish(string $id, ?string $locale = null): array
    {
        $p = $this->find($id);
        $t = $this->version($p, $locale ?? $this->mainLocale);
        if ($this->isRoot($p)) {
            throw ContentException::conflict(I18n::t('content.page_home_not_unpublishable'));
        }
        if (!$t->isLive()) {
            throw ContentException::conflict(I18n::t('content.page_already_unpublished'));
        }
        $this->hooks->unpublishing($this->record($p, $t));
        $was = $this->history->pageState($p, $t);
        $t->status = PageTranslation::DRAFT;
        $t->publishedUrl = null;
        $this->history->page($p, $t, Revision::UNPUBLISH, $was);
        $this->em->flush();
        $this->hooks->unpublished($this->record($p, $t));

        return $this->flat($p, $t);
    }

    /** Takes back unpublish(): the version is on the site again exactly as it was. */
    public function republish(string $id, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $p = $this->find($id);
        $t = $this->version($p, $locale);
        if ($t->isLive() || null === $t->published) {
            throw ContentException::conflict(I18n::t('content.page_republished'));
        }
        $url = Url::key((string) ($t->published['url'] ?? ''));
        foreach ($this->translations->findForLocale($locale) as $other) {
            if ($other->id !== $t->id && $other->publishedUrl === $url) {
                throw ContentException::conflict(I18n::t('content.page_url_taken_published'));
            }
        }
        $was = $this->history->pageState($p, $t);
        $t->status = PageTranslation::PUBLISHED;
        $t->publishedUrl = $url;
        $this->history->page($p, $t, Revision::PUBLISH, $was);
        $this->em->flush();
        $this->hooks->published($this->record($p, $t)); // live again: the sitemap and llms.txt caches and the hooks of the site hear it as a publish

        return $this->flat($p, $t);
    }

    /* ---------- shapes ---------- */

    /**
     * The flat page of one language version.
     *
     * @return array<string, mixed>
     */
    public function flat(Page $p, PageTranslation $t): array
    {
        $d = $this->hide($this->present($t->draft), $p->template);

        return [
            'id' => $p->id,
            'parentId' => $p->parentId(),
            'template' => $p->template,
            'locale' => $t->locale,
            'translated' => true,
            'title' => (string) ($d['title'] ?? ''),
            'url' => (string) ($d['url'] ?? ''),
            'seo' => $d['seo'] ?? ['title' => '', 'description' => ''],
            'sections' => $d['sections'] ?? [],
            'fields' => $d['fields'] ?? new \stdClass(),
            'status' => $t->status,
            'updatedAt' => $this->iso($t->updatedAt),
            'version' => $this->versionOf($p, $t),
            'migrated' => $t->migrated,
            'awaiting' => $t->awaiting,
            'returned' => $t->returned,
        ];
    }

    /** What the editor sends back as `baseVersion`: it changes when the draft, the place of the page or its template change (not with publishing). */
    public function versionOf(Page $p, PageTranslation $t): string
    {
        return $this->versions->of(['draft' => $t->draft, 'parentId' => $p->parentId(), 'template' => $p->template]);
    }

    /**
     * Makes empty objects objects (`fields`, section `data`) so they reach the browser as `{}`.
     *
     * @param array<string, mixed> $v draft or snapshot
     *
     * @return array<string, mixed>
     */
    public function present(array $v): array
    {
        $v['fields'] = Json::obj($v['fields'] ?? []);
        $v['seo'] = (array) ($v['seo'] ?? []) + ['title' => '', 'description' => ''];
        $sections = [];
        foreach ($v['sections'] ?? [] as $s) {
            $s['data'] = Json::obj($s['data'] ?? []);
            $s['hidden'] = !empty($s['hidden']);
            $sections[] = $s;
        }
        $v['sections'] = $sections;

        return $v;
    }

    /** @return array<string, mixed> the ROW of the list */
    public function row(Page $p, string $locale): array
    {
        $t = $p->translation($locale);
        $src = $t ?? $p->translation($this->mainLocale) ?? ($p->translations->first() ?: null);
        $d = null !== $src ? $this->hide($this->present($src->draft), $p->template, false) : ['title' => '', 'url' => '', 'seo' => ['title' => '', 'description' => ''], 'sections' => [], 'fields' => new \stdClass()];
        $n = null !== $t ? $this->changeCount($t) : 0;

        return [
            'id' => $p->id,
            'parentId' => $p->parentId(),
            'position' => $p->position,
            'template' => $p->template,
            'locale' => $locale,
            'translated' => null !== $t,
            'title' => (string) ($d['title'] ?? ''),
            'url' => (string) ($d['url'] ?? ''),
            'seo' => $d['seo'],
            'fields' => $d['fields'],
            'status' => null !== $t ? $t->status : 'missing',
            'updatedAt' => null !== $t ? $this->iso($t->updatedAt) : null,
            'migrated' => null !== $t && $t->migrated,
            'sectionsCount' => \count($d['sections'] ?? []),
            'changed' => $n > 0,
            'changes' => $n,
            'published' => null !== $t && $t->isLive(),
            'awaiting' => $t?->awaiting,
            'returned' => $t?->returned,
            'locales' => $this->summary($p),
        ];
    }

    /** @return array<string, array<string, mixed>|null> */
    public function summary(Page $p): array
    {
        $out = [$this->mainLocale => null];
        foreach ($p->translations as $locale => $t) {
            $n = $this->changeCount($t);
            $d = $t->draft;
            $out[$locale] = [
                'status' => $t->status,
                'changed' => $n > 0,
                'changes' => $n,
                'published' => $t->isLive(),
                'title' => (string) ($d['title'] ?? ''),
                'url' => (string) ($d['url'] ?? ''),
                'updatedAt' => $this->iso($t->updatedAt),
                'migrated' => $t->migrated,
                'sectionsCount' => \count($d['sections'] ?? []),
                'awaiting' => $t->awaiting,
            ];
        }

        return $out;
    }

    public function iso(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /* ---------- internals ---------- */

    private function version(Page $p, string $locale): PageTranslation
    {
        return $p->translation($locale) ?? throw new ContentException(I18n::t(self::NO_VERSION), 404, ['code' => 'no_translation', 'locale' => $locale]);
    }

    /** The record of a page as hooks see it: its main-language version, else the first one. */
    private function recordOf(Page $p): Record
    {
        return $this->record($p, $p->translation($this->mainLocale) ?? $p->translations->first());
    }

    /**
     * @param list<Record> $records
     *
     * @return array<string, list<Record>> by template key
     */
    private function byTemplate(array $records): array
    {
        $out = [];
        foreach ($records as $r) {
            $out[$r->resourceKey][] = $r;
        }

        return $out;
    }

    /** @return list<string> keys of the template fields the current person does not see */
    private function hiddenFields(string $template): array
    {
        return $this->access->hiddenKeys($this->schema->template($template)?->fieldList() ?? []);
    }

    /**
     * Leaves out the template fields and the section fields the current person may not see.
     *
     * @param array<string, mixed> $v draft or snapshot
     *
     * @return array<string, mixed>
     */
    private function hide(array $v, string $template, bool $withSections = true): array
    {
        $fields = $this->schema->template($template)?->fieldList() ?? [];
        if ([] !== $fields && [] !== $this->access->hiddenKeys($fields)) {
            $v['fields'] = Json::obj($this->access->output($fields, (array) ($v['fields'] ?? [])));
        }
        if (!$withSections || [] === ($v['sections'] ?? [])) {
            return $v;
        }
        $sections = [];
        $lists = [];
        foreach ((array) $v['sections'] as $s) {
            $type = (string) ($s['type'] ?? '');
            $lists[$type] ??= $this->schema->section($type)?->fieldList() ?? [];
            if ([] !== $lists[$type] && [] !== $this->access->hiddenKeys($lists[$type])) {
                $s['data'] = Json::obj($this->access->output($lists[$type], (array) ($s['data'] ?? [])));
            }
            $sections[] = $s;
        }
        $v['sections'] = $sections;

        return $v;
    }

    /**
     * Where the person may not change a section field (he cannot see it, or it is readonly()), the stored value stays; a new section gets no value for it.
     * Sections are matched by id.
     *
     * @param list<mixed> $incoming
     * @param list<array<string, mixed>> $stored
     *
     * @return list<mixed>
     */
    private function guardSections(array $incoming, array $stored): array
    {
        $byId = [];
        foreach ($stored as $s) {
            $byId[(string) ($s['id'] ?? '')] = $s;
        }
        $out = [];
        foreach ($incoming as $s) {
            $list = \is_array($s) ? ($this->schema->section((string) ($s['type'] ?? ''))?->fieldList() ?? []) : [];
            $open = $this->access->writable($list);
            if (\count($open) === \count($list)) {
                $out[] = $s;
                continue;
            }
            $old = $byId[(string) ($s['id'] ?? '')] ?? null;
            $oldData = null !== $old && ($old['type'] ?? null) === ($s['type'] ?? null) ? (array) ($old['data'] ?? []) : [];
            $data = $this->access->input($list, \is_array($s['data'] ?? null) ? $s['data'] : []);
            foreach ($list as $f) {
                if (!\in_array($f, $open, true) && \array_key_exists($f->key(), $oldData)) {
                    $data[$f->key()] = $oldData[$f->key()];
                }
            }
            $s['data'] = $data;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * The change rows without the fields the person may not see: template fields (`field`) and section fields (`edit`, matched to the section type by id).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $draft
     * @param list<string> $hidden keys of the template fields he may not see
     *
     * @return list<array<string, mixed>>
     */
    private function visibleChanges(array $rows, array $draft, array $hidden): array
    {
        $bySection = [];
        $types = [];
        foreach ((array) ($draft['sections'] ?? []) as $s) {
            $type = (string) ($s['type'] ?? '');
            $types[$type] ??= $this->access->hiddenKeys($this->schema->section($type)?->fieldList() ?? []);
            $bySection[(string) ($s['id'] ?? '')] = $types[$type];
        }

        return \array_values(\array_filter($rows, static function (array $c) use ($hidden, $bySection): bool {
            $key = $c['key'] ?? null;
            if ('field' === $c['kind']) {
                return !\in_array($key, $hidden, true);
            }

            return !('edit' === $c['kind'] && \in_array($key, $bySection[(string) ($c['sec'] ?? '')] ?? [], true));
        }));
    }

    private function actor(): string
    {
        $u = $this->security->getUser();

        return $u instanceof User && '' !== $u->name ? $u->name : I18n::t('content.actor_default');
    }

    private function isRoot(Page $p): bool
    {
        return (bool) $this->schema->template($p->template)?->isRoot();
    }

    /** @return array<string, mixed> */
    private function snapshot(array $draft): array
    {
        return [
            'title' => $draft['title'] ?? '',
            'url' => $draft['url'] ?? '',
            'seo' => $draft['seo'] ?? ['title' => '', 'description' => ''],
            'sections' => $draft['sections'] ?? [],
            'fields' => $draft['fields'] ?? [],
        ];
    }

    /** The pages table as the template guards read it: { id, parentId, template, title }. @return list<array<string, mixed>> */
    private function guardRows(): array
    {
        $out = [];
        foreach ($this->pages->findAllOrdered() as $p) {
            $t = $p->translation($this->mainLocale) ?? ($p->translations->first() ?: null);
            $out[] = ['id' => $p->id, 'parentId' => $p->parentId(), 'template' => $p->template, 'title' => (string) ($t?->draft['title'] ?? '')];
        }

        return $out;
    }

    /** Addresses the core answers itself, before any page: a page there would never be shown. Address => what is there (for the message). */
    private const RESERVED_URLS = [
        '/mcp' => 'content.reserved_url_mcp',
        '/healthz' => 'content.reserved_url_healthz',
        '/robots.txt' => 'content.reserved_url_robots',
        '/llms.txt' => 'content.reserved_url_llms',
    ];

    private static function assertNotReserved(string $url): void
    {
        $what = self::RESERVED_URLS[Url::key($url)] ?? null;
        if (null !== $what) {
            throw ContentException::invalid(I18n::t('content.reserved_url', ['url' => \trim($url), 'what' => I18n::t($what)]));
        }
    }

    /** Is `url` already the address of another page version in `locale` (draft or live)? An empty address is never «taken». */
    private function urlTaken(string $locale, string $url, ?string $exceptPageId): bool
    {
        $key = Url::key($url);
        if ('' === $key) {
            return false;
        }
        foreach ($this->translations->findForLocale($locale) as $t) {
            if ($t->page->id === $exceptPageId) {
                continue;
            }
            if (Url::key((string) ($t->draft['url'] ?? '')) === $key || $t->publishedUrl === $key) {
                return true;
            }
        }

        return false;
    }

    /** Key of the template field that is the page's heading (and the page name), or null. */
    private function nameKey(Template $tpl): ?string
    {
        $h1 = $tpl->h1Field();
        if (null !== $h1 && null !== $tpl->field($h1)) {
            return $h1;
        }

        return null !== $tpl->field('title') ? 'title' : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the flat page
     */
    private function writeVersion(string $id, string $locale, array $data, bool $withSections, string $action = Revision::EDIT): array
    {
        $p = $this->find($id);
        $t = $this->version($p, $locale);
        $was = $this->history->pageState($p, $t);
        $cur = $t->draft;
        $known = $this->schema->template($p->template);
        if (null !== $known && \is_array($data['fields'] ?? null)) {
            $data['fields'] = $this->access->input($known->fieldList(), $data['fields']);
        }
        if (null !== $known && \is_array($data['sections'] ?? null)) {
            $data['sections'] = $this->guardSections($data['sections'], (array) ($cur['sections'] ?? []));
        }
        $data = $this->hooks->updating($this->record($p, $t), $data);

        // the home page keeps its address and its place at the top level
        if ($this->isRoot($p)) {
            if (isset($data['url']) && \trim((string) $data['url']) !== (string) ($cur['url'] ?? '')) {
                throw ContentException::invalid(I18n::t('content.page_home_url_locked'));
            }
            if (\array_key_exists('parentId', $data) && ($data['parentId'] ?: null) !== $p->parentId()) {
                throw ContentException::invalid(I18n::t('content.page_home_top_level'));
            }
        }

        $tplChanged = $this->applySharedChange($p, $data);
        $next = $t->draft;
        $tpl = $this->schema->template($p->template);
        if (\array_key_exists('title', $data)) {
            $next['title'] = \trim((string) $data['title']);
        }
        if (\array_key_exists('url', $data)) {
            $next['url'] = \trim((string) $data['url']);
        }
        if (\array_key_exists('seo', $data) && \is_array($data['seo'])) {
            $next['seo'] = [
                'title' => \trim((string) ($data['seo']['title'] ?? '')),
                'description' => \trim((string) ($data['seo']['description'] ?? '')),
            ];
        }
        if ($withSections && !$tplChanged && \array_key_exists('sections', $data) && \is_array($data['sections'])) {
            $next['sections'] = false === $tpl?->hasSections() ? [] : $this->normalizeSections($data['sections']);
        }
        if (!$tplChanged && \array_key_exists('fields', $data) && \is_array($data['fields']) && null !== $tpl) {
            $next['fields'] = $this->values->normalize($tpl->fieldList(), $data['fields']) + (array) ($next['fields'] ?? []);
        }
        if (null !== $tpl) {
            $this->raw->assertFields($tpl->fieldList(), (array) ($cur['fields'] ?? []), (array) ($next['fields'] ?? []));
        }
        $this->raw->assertSections((array) ($cur['sections'] ?? []), (array) ($next['sections'] ?? []));
        if (null !== $tpl) {
            $next = $this->syncH1($tpl, $cur, $next);
        }
        if (Url::key((string) ($next['url'] ?? '')) !== Url::key((string) ($cur['url'] ?? ''))) {
            self::assertNotReserved((string) $next['url']);
            if ($this->urlTaken($locale, (string) $next['url'], $p->id)) {
                throw ContentException::conflict(I18n::t('content.page_url_exists'));
            }
        }
        $t->draft = $next;
        $t->migrated = false;
        $t->updatedAt = new \DateTimeImmutable();
        $this->history->page($p, $t, $action, $was);
        $this->em->flush();
        $this->hooks->updated($this->record($p, $t));

        return $this->flat($p, $t);
    }

    /**
     * The shared part of a change: the parent page and the template. Returns true when the template changed.
     *
     * @param array<string, mixed> $patch
     */
    private function applySharedChange(Page $p, array $patch): bool
    {
        $nextParentId = \array_key_exists('parentId', $patch) ? ($patch['parentId'] ?: null) : $p->parentId();
        $nextTpl = !empty($patch['template']) ? (string) $patch['template'] : $p->template;
        $moved = $nextParentId !== $p->parentId();
        $tplChanged = $nextTpl !== $p->template;
        if (!$moved && !$tplChanged) {
            return false;
        }
        if ($tplChanged && $this->isRoot($p)) {
            throw ContentException::invalid(I18n::t('content.page_home_template_locked'));
        }
        if ($tplChanged && null === $this->schema->template($nextTpl)) {
            throw ContentException::invalid(I18n::t('content.page_template_unknown'));
        }
        $parent = null;
        if (null !== $nextParentId) {
            $parent = $this->pages->find($nextParentId) ?? throw ContentException::invalid(I18n::t('content.page_parent_missing'));
            if ($parent->id === $p->id) {
                throw ContentException::invalid(I18n::t('content.page_own_parent'));
            }
            for ($cur = $parent, $guard = 0; null !== $cur && $guard < 50; $cur = $cur->parent, ++$guard) {
                if ($cur->id === $p->id) {
                    throw ContentException::invalid(I18n::t('content.page_parent_is_descendant'));
                }
            }
        }
        $rows = $this->guardRows();
        $parentRow = null !== $parent ? ['id' => $parent->id, 'template' => $parent->template] : null;
        $me = ['id' => $p->id, 'parentId' => $nextParentId, 'template' => $p->template, 'title' => ''];
        $r = $tplChanged ? $this->placement->canChangeTemplate($me, $nextTpl, $rows) : $this->placement->canPlace($p->template, $parentRow, $rows, $p->id);
        if (!$r['ok']) {
            throw ContentException::invalid($r['reason']);
        }
        if ($moved) {
            $p->parent = $parent;
            $p->position = $this->pages->nextPosition($parent);
        }
        if ($tplChanged) {
            $p->template = $nextTpl;
            $tpl = $this->schema->template($nextTpl);
            foreach ($p->translations as $v) {
                $d = $v->draft;
                $fields = [];
                foreach ($tpl->fieldList() as $f) {
                    $old = ($d['fields'] ?? [])[$f->key()] ?? null;
                    $fields[$f->key()] = null !== $old ? $old : $this->values->emptyFor($f);
                }
                $k = $this->nameKey($tpl);
                if (null !== $k && '' === \trim((string) ($fields[$k] ?? ''))) {
                    $fields[$k] = $this->titleAsField($tpl->field($k), (string) ($d['title'] ?? ''));
                }
                $d['fields'] = $fields;
                if (!$tpl->hasSections()) {
                    $d['sections'] = [];
                }
                $v->draft = $d;
            }
        }

        return $tplChanged;
    }

    /**
     * Page name and the heading field of a template with h1 follow each other: whichever changed in `next` (against `cur`) wins.
     * A `headline` heading may carry line breaks and gradient words: the page name is then its FIRST LINE as plain text (the rest of the
     * heading is decoration); a renamed page replaces only that first line and keeps the other lines.
     *
     * @param array<string, mixed> $cur
     * @param array<string, mixed> $next
     *
     * @return array<string, mixed>
     */
    private function syncH1(Template $tpl, array $cur, array $next): array
    {
        $k = $tpl->h1Field();
        $field = null === $k ? null : $tpl->field($k);
        if (null === $k || null === $field) {
            return $next;
        }
        $f = (array) ($next['fields'] ?? []);
        $headline = $field->isHeadline();
        if (((string) ($f[$k] ?? '')) !== ((string) (($cur['fields'] ?? [])[$k] ?? ''))) {
            $next['title'] = $headline ? $this->html->firstLine($f[$k] ?? '') : (string) ($f[$k] ?? '');
        } elseif (($next['title'] ?? null) !== ($cur['title'] ?? null)) {
            $title = (string) ($next['title'] ?? '');
            if (!$headline) {
                $f[$k] = $title;
            } elseif ($this->html->firstLine($f[$k] ?? '') !== \trim($title)) {
                $rest = \preg_match('~<br\s*/?>(.*)$~isu', (string) ($f[$k] ?? ''), $m) ? '<br>' . $m[1] : '';
                $f[$k] = HtmlSanitizer::escape(\trim($title)) . $rest;
            }
            $next['fields'] = $f;
        }

        return $next;
    }

    /** The page name as the value of the heading field: a headline holds HTML, so the name is escaped. */
    private function titleAsField(?FieldInterface $field, string $title): string
    {
        return null !== $field && $field->isHeadline() ? HtmlSanitizer::escape($title) : $title;
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeSections(array $sections): array
    {
        $out = [];
        $seen = [];
        foreach (\array_values($sections) as $s) {
            if (!\is_array($s)) {
                continue;
            }
            $id = (string) ($s['id'] ?? '');
            if ('' === $id || isset($seen[$id])) {
                $id = Json::nid('s');
            }
            $seen[$id] = true;
            $type = (string) ($s['type'] ?? '');
            $data = \is_array($s['data'] ?? null) ? $s['data'] : [];
            $def = $this->schema->section($type);
            $out[] = [
                'id' => $id,
                'type' => $type,
                'hidden' => !empty($s['hidden']),
                'data' => null !== $def ? $this->values->normalize($def->fieldList(), $data) : $data,
            ];
        }

        return $out;
    }

    /**
     * The first section a new page gets: the first «Первый экран» type, with the title as its headline.
     *
     * @return list<array<string, mixed>>
     */
    public function defaultSections(string $title): array
    {
        foreach ($this->schema->sections() as $def) {
            if (!$def->isFirstScreen()) {
                continue;
            }
            $data = $this->values->defaults($def->fieldList());
            foreach ($def->fieldList() as $f) {
                if ($f->isHeadline()) {
                    $data[$f->key()] = HtmlSanitizer::escape(\mb_substr(\trim($title), 0, $f->maxLength() ?? 60));
                    break;
                }
            }

            return [['id' => Json::nid('s'), 'type' => $def->key(), 'hidden' => false, 'data' => $data]];
        }

        return [];
    }
}

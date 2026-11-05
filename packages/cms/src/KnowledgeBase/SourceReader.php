<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\KnowledgeBase\Model\LiveSource;
use Itk\Cms\KnowledgeBase\Model\Source;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Schema\Schema;

/**
 * Which sources belong in the index, and their published content. The one place of the inclusion policy:
 *   a page version: what Content\IndexablePages selects for search engines (main language, live, not noindex), its template known and not excluded
 *   an item version: main language, translations[locale].status 'published' with a published array, its collection known and not excluded
 * Never the draft. Reads the database as it is now (no cache across messages).
 *
 * @internal
 */
final class SourceReader
{
    public function __construct(
        private readonly PageTranslationRepository $translations,
        private readonly CollectionItemRepository $items,
        private readonly IndexablePages $indexable,
        private readonly Schema $schema,
        private readonly HtmlSanitizer $html,
        private readonly KnowledgeBaseOptions $options,
    ) {
    }

    /** The live, included source, or null (draft, unpublished, deleted, noindex, excluded, template or collection gone from the schema). */
    public function read(SourceRef $ref): ?LiveSource
    {
        if (SourceKind::Page === $ref->kind) {
            $t = $this->translations->findOneBy(['page' => $ref->id, 'locale' => $ref->locale]);

            return null === $t ? null : $this->page($t);
        }
        $item = $this->items->find($ref->id);

        return null === $item ? null : $this->item($item, $ref->locale);
    }

    /**
     * Every live, included source of the site: what itk:knowledge-base:sync compares with the stored documents.
     *
     * @return list<SourceRef>
     */
    public function all(): array
    {
        $refs = [];
        foreach ($this->translations->findAllLive() as $t) {
            if (null !== $this->page($t)) {
                $refs[] = new SourceRef(SourceKind::Page, $t->page->id, $t->locale);
            }
        }
        foreach ($this->schema->collections() as $collection) {
            if (!$this->options->indexes(SourceKind::Item, $collection->key(), $this->options->mainLocale)) {
                continue;
            }
            foreach ($this->items->findByCollection($collection->key()) as $item) {
                if (null !== $this->item($item, $this->options->mainLocale)) {
                    $refs[] = new SourceRef(SourceKind::Item, $item->id, $this->options->mainLocale);
                }
            }
        }

        return $refs;
    }

    /**
     * The language versions of one source, live or not, for the command's --source without a locale.
     *
     * @return list<SourceRef>
     */
    public function versionsOf(SourceRef $any): array
    {
        if (SourceKind::Page === $any->kind) {
            $locales = \array_map(static fn (PageTranslation $t): string => $t->locale, $this->translations->findBy(['page' => $any->id]));
        } else {
            $locales = \array_map('strval', \array_keys($this->items->find($any->id)->translations ?? []));
        }

        return \array_map(static fn (string $locale): SourceRef => new SourceRef($any->kind, $any->id, $locale), \array_values($locales));
    }

    private function page(PageTranslation $t): ?LiveSource
    {
        $template = $t->page->template;
        if (!$this->options->indexes(SourceKind::Page, $template, $t->locale) || null === $this->schema->template($template) || !$this->indexable->admits($t)) {
            return null;
        }
        $published = (array) $t->published;
        $title = \trim((string) ($published['title'] ?? ''));
        $url = (string) ($published['url'] ?? $t->publishedUrl);

        return new LiveSource(new Source(SourceKind::Page, $t->page->id, $t->locale, $template, $title, $url), $published, $t->id);
    }

    private function item(CollectionItem $item, string $locale): ?LiveSource
    {
        $collection = $this->schema->collection($item->collection);
        $version = $item->translations[$locale] ?? null;
        if (null === $collection || !$this->options->indexes(SourceKind::Item, $item->collection, $locale) || !\is_array($version) || 'published' !== ($version['status'] ?? null) || !\is_array($version['published'] ?? null)) {
            return null;
        }
        $published = $version['published'];
        $title = $this->html->oneLine($published[$collection->titleFieldKey()] ?? '');

        return new LiveSource(new Source(SourceKind::Item, $item->id, $locale, $item->collection, $title, null), $published, null);
    }
}

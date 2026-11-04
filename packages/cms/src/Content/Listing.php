<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\Page;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The list a page fills by itself (template->listing): published pages below it, or the published records of a collection. */
final class Listing
{
    public function __construct(
        private readonly Schema $schema,
        private readonly PageRepository $pages,
        private readonly PageTranslationRepository $translations,
        private readonly CollectionService $collections,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * @param string $pageId id of the page whose list is wanted
     *
     * @return list<array<string, mixed>> published pages { id, template, title, url, seo, fields, sections } in tree order (parent position, then own position)
     */
    public function forPage(string $pageId, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $page = $this->pages->find($pageId);
        if (null === $page) {
            return [];
        }
        $rule = $this->schema->template($page->template)?->listingRule();
        if (null === $rule) {
            return [];
        }
        if (isset($rule['collection'])) {
            return $this->collections->published((string) $rule['collection'], $locale);
        }
        $of = (array) ($rule['of'] ?? []);
        $onlyIf = $rule['onlyIf'] ?? null;

        $live = [];
        foreach ($this->translations->findLiveForLocale($locale) as $t) {
            $live[$t->page->id] = $t;
        }
        $children = [];
        foreach ($this->pages->findAllOrdered() as $p) {
            $children[$p->parentId() ?? ''][] = $p;
        }
        $out = [];
        $walk = static function (Page $parent) use (&$walk, &$out, $children, $live, $of, $onlyIf): void {
            foreach ($children[$parent->id] ?? [] as $c) {
                $t = $live[$c->id] ?? null;
                if (null !== $t && \in_array($c->template, $of, true) && (null === $onlyIf || !empty(($t->published['fields'] ?? [])[$onlyIf]))) {
                    $out[] = ['id' => $c->id, 'template' => $c->template] + (array) $t->published;
                }
                $walk($c);
            }
        };
        $walk($page);

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

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
     * @return list<array<string, mixed>> published pages { id, template, parentId, title, url, seo, fields, sections } in tree order (parent position, then own position)
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

        $children = [];
        $eligible = [];
        foreach ($this->pages->descendants($pageId) as $p) {
            $children[$p['parent_id']][] = $p;
            if (\in_array($p['template'], $of, true)) {
                $eligible[] = $p['id'];
            }
        }
        $live = $this->translations->findLiveSnapshotsForPages($locale, $eligible);
        $out = [];
        $walk = static function (string $parentId) use (&$walk, &$out, $children, $live, $onlyIf): void {
            foreach ($children[$parentId] ?? [] as $c) {
                $snapshot = $live[$c['id']] ?? null;
                if (null !== $snapshot && (null === $onlyIf || !empty(($snapshot['fields'] ?? [])[$onlyIf]))) {
                    $out[] = ['id' => $c['id'], 'template' => $c['template'], 'parentId' => $parentId] + $snapshot;
                }
                $walk($c['id']);
            }
        };
        $walk($pageId);

        return $out;
    }
}

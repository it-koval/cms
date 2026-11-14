<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Repository\PageTranslationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The live pages of the main language that a search engine or an AI service may be pointed at: the one selection behind the sitemap and llms.txt.
 *  - the other languages are left out until the public route serves them (it answers with the main language only);
 *  - a page whose robots (its own SEO or the SEO rule of its address) say `noindex` is left out;
 *  - the SEO of a page is the one of its published version with the rule of its address laid over it.
 *
 * @internal
 */
final class IndexablePages
{
    public function __construct(
        private readonly PageTranslationRepository $translations,
        private readonly SeoResolver $seo,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * One row per address, in the order of the pages.
     *
     * @return list<array{url: string, title: string, description: string, locale: string, publishedAt: \DateTimeImmutable|null}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->translations->findAllLive() as $t) {
            if (!$this->admits($t)) {
                continue;
            }
            $version = (array) $t->published;
            $url = (string) ($version['url'] ?? $t->publishedUrl);
            $seo = $this->seo->apply((array) ($version['seo'] ?? []), $this->seo->best($url, [], $t->locale));
            $title = \trim((string) ($seo['title'] ?? ''));
            $rows[$url] = [
                'url' => $url,
                'title' => '' !== $title ? $title : \trim((string) ($version['title'] ?? '')),
                'description' => \trim((string) ($seo['description'] ?? '')),
                'locale' => $t->locale,
                'publishedAt' => $t->publishedAt,
            ];
        }

        return \array_values($rows);
    }

    /** Whether one page version is in that selection: the main language, live, at a path, not `noindex` by its SEO or the rule of its address. */
    public function admits(PageTranslation $t): bool
    {
        if ($t->locale !== $this->mainLocale || !$t->isLive()) {
            return false;
        }
        $version = (array) $t->published;
        $url = (string) ($version['url'] ?? $t->publishedUrl);

        return \str_starts_with($url, '/') && !$this->noindex((array) ($version['seo'] ?? []), $url, $t->locale);
    }

    /**
     * Whether the SEO of a page, with the rule of its address laid over it, says `noindex`.
     *
     * @param array<string, mixed> $pageSeo the `seo` of the published snapshot
     */
    public function noindex(array $pageSeo, string $url, string $locale): bool
    {
        $seo = $this->seo->apply($pageSeo, $this->seo->best($url, [], $locale));

        return \str_contains(\strtolower((string) ($seo['robots'] ?? '')), 'noindex');
    }
}

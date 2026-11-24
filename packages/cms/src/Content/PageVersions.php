<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Support\Url;

/** @internal */
final class PageVersions
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly IndexablePages $indexable,
        private readonly ?PageRepository $pages = null,
    ) {
    }

    /** Load complete translation collections for a renderer batch in one query.
     *
     * @param list<PageTranslation> $translations
     */
    public function prepare(array $translations): void
    {
        if (null === $this->pages || [] === $translations) {
            return;
        }
        $ids = \array_values(\array_unique(\array_map(static fn (PageTranslation $t): string => $t->page->id, $translations)));
        // Do not filter the joined collection: Doctrine must not mark an incomplete collection initialized.
        $this->pages->createQueryBuilder('p')->addSelect('t')->leftJoin('p.translations', 't')
            ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
    }

    /** @return list<array{code: string, name: string, url: string, path: string, noindex: bool}> */
    public function live(Page $page): array
    {
        $live = [];
        foreach ($page->translations as $translation) {
            if ($translation->isLive() && $this->locales->serves($translation->locale, (string) ($translation->published['url'] ?? $translation->publishedUrl))) {
                $live[$translation->locale] = $translation->published;
            }
        }

        $versions = [];
        foreach ($this->locales->codes() as $code) {
            if (!isset($live[$code])) {
                continue;
            }
            $published = $live[$code];
            $path = (string) ($published['url'] ?? '');
            $versions[] = [
                'code' => $code,
                'name' => $this->locales->name($code),
                'url' => Url::encodePath(Url::spelling($path)),
                'path' => $path,
                'noindex' => $this->indexable->noindex((array) ($published['seo'] ?? []), $path, $code),
            ];
        }

        return $versions;
    }

    /** @return list<array{hreflang: string, href: string}> */
    public function alternates(Page $page, ?string $base): array
    {
        if (null === $base || !$this->locales->isMultilingual()) {
            return [];
        }
        $rows = [];
        foreach ($this->live($page) as $version) {
            if (!$version['noindex']) {
                $rows[] = ['locale' => $version['code'], 'url' => $version['path']];
            }
        }

        return $this->alternatesForRows($rows, $base);
    }

    /**
     * The same link set for the already selected sitemap rows, without loading each page's translations again.
     *
     * @param list<array{locale: string, url: string}> $rows indexable live versions of one page
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function alternatesForRows(array $rows, ?string $base): array
    {
        if (!$this->locales->isMultilingual() || null === $base || !PublicUrl::isValid($base)) {
            return [];
        }
        $urls = [];
        foreach ($rows as $row) {
            if ($this->locales->serves($row['locale'], $row['url']) && \str_starts_with($row['url'], '/')) {
                $urls[$row['locale']] = \rtrim($base, '/') . Url::encodePath(Url::spelling($row['url']));
            }
        }
        if (\count($urls) < 2) {
            return [];
        }
        $alternates = [];
        foreach ($this->locales->codes() as $code) {
            if (isset($urls[$code])) {
                $alternates[] = ['hreflang' => $this->locales->htmlLang($code), 'href' => $urls[$code]];
            }
        }
        if (isset($urls[$this->locales->main()])) {
            $alternates[] = ['hreflang' => 'x-default', 'href' => $urls[$this->locales->main()]];
        }

        return $alternates;
    }
}

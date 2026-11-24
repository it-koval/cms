<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Support\Url;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * GET /sitemap.xml: the indexable live versions, one <url> each, the same rule for every site.
 *  - the pages are the ones of IndexablePages: the enabled languages, without `noindex`;
 *  - multilingual addresses use PublicUrl, the same trusted base as HTML. A single-language site uses a valid public_url, then the `domain` setting
 *    (written the way 0.2.2 wrote it); without a base there is no sitemap (the route answers 404);
 *  - <lastmod> is the day the page was published;
 *  - the XML is kept in the cache for an hour and dropped when a page is published, unpublished or deleted, or an SEO rule changes (SitemapInvalidator).
 */
final class SitemapBuilder
{
    private const KEY = 'itk_sitemap_';
    private const TTL = 3600;

    public function __construct(
        private readonly IndexablePages $pages,
        private readonly SettingsService $settings,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface&CacheItemPoolInterface $cache,
        private readonly ?PageVersions $versions = null,
        private readonly ?PublicUrl $publicUrl = null,
        private readonly ?CrawlerCacheGeneration $generation = null,
    ) {
    }

    /** The XML, or null when the site address is not set. */
    public function xml(): ?string
    {
        $base = $this->xmlBase();
        if (null === $base) {
            return null;
        }

        // Capture before selecting rows: an old build can only save under its old generation.
        $generation = $this->generations()->current('sitemap');
        $sig = \hash('sha256', $base . ($this->isMultilingual() ? "\0" . $this->pages->catalogSignature() : ''));

        return $this->cache->get(self::KEY . $generation . '_' . $sig, function (ItemInterface $item) use ($base): string {
            $item->expiresAfter(self::TTL);

            return $this->build($base);
        });
    }

    /** Advance only after the content change committed; late writes to the previous generation stay unreachable. */
    public function forget(): void
    {
        $this->generations()->advance('sitemap');
    }

    private function generations(): CrawlerCacheGeneration
    {
        return $this->generation ?? new CrawlerCacheGeneration($this->cache);
    }

    /** Domain-only single-language XML retains the spelling shipped by 0.2.2; other consumers use canonical origins. */
    private function xmlBase(): ?string
    {
        if ($this->isMultilingual() && null !== $this->publicUrl) {
            return $this->publicUrl->seoBase(); // the same base as the hreflang links of the HTML
        }
        $base = $this->base();
        if ($this->isMultilingual() || null !== $this->publicUrl?->configuredSiteAddress()) {
            return $base;
        }
        $domain = \rtrim(\trim((string) $this->settings->get('domain', null, '')), '/');
        if (1 !== \preg_match('~^(https?://)?[\p{L}\p{N}][\p{L}\p{N}.\-]*(:\d{1,5})?$~u', $domain)) {
            return $base;
        }

        return \preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain;
    }

    /** The trusted SEO base. Null when no address is available. */
    public function base(): ?string
    {
        return $this->address()?->origin();
    }

    /** The configured address for host vouching and search engines. */
    public function address(): ?SiteAddress
    {
        return $this->publicUrl?->siteAddress() ?? SiteAddress::fromDomain((string) $this->settings->get('domain', null, ''));
    }

    public function isMultilingual(): bool
    {
        return $this->pages->isMultilingual();
    }

    private function build(string $base): string
    {
        $pages = $this->pages->rows();
        $groups = [];
        foreach ($pages as $page) {
            $groups[$page['pageId']][] = ['locale' => $page['locale'], 'url' => $page['url']];
        }
        $alternates = [];
        if ($this->isMultilingual()) {
            foreach ($groups as $id => $group) {
                $alternates[$id] = $this->versions?->alternatesForRows($group, $base) ?? [];
            }
        }
        $rows = [];
        foreach ($pages as $page) {
            $loc = $base . Url::encodePath($this->isMultilingual() ? Url::spelling($page['url']) : $page['url']);
            $rows[$loc] = ['alternates' => $alternates[$page['pageId']] ?? [], 'loc' => $loc, 'lastmod' => $page['publishedAt']?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        }
        $rows = \array_values($rows);
        \usort($rows, static fn (array $a, array $b): int => $a['loc'] <=> $b['loc']);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ($this->isMultilingual() ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '') . '>' . "\n";
        foreach ($rows as $r) {
            $xml .= '  <url><loc>' . \htmlspecialchars($r['loc'], \ENT_XML1 | \ENT_QUOTES, 'UTF-8') . '</loc>'
                . (null !== $r['lastmod'] ? '<lastmod>' . $r['lastmod'] . '</lastmod>' : '');
            foreach ($r['alternates'] as $alternate) {
                $xml .= '<xhtml:link rel="alternate" hreflang="' . \htmlspecialchars($alternate['hreflang'], \ENT_XML1 | \ENT_QUOTES, 'UTF-8')
                    . '" href="' . \htmlspecialchars($alternate['href'], \ENT_XML1 | \ENT_QUOTES, 'UTF-8') . '"/>';
            }
            $xml .= "</url>\n";
        }

        return $xml . "</urlset>\n";
    }
}

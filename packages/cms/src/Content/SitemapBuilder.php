<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Support\Url;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * GET /sitemap.xml: the indexable live versions, one <url> each, the same rule for every site.
 *  - the pages are the ones of IndexablePages: the enabled languages, without `noindex`;
 *  - multilingual addresses use PublicUrl, the same trusted base as HTML. Legacy sites keep the `domain` setting as their only base;
 *    without a base there is no sitemap (the route answers 404);
 *  - <lastmod> is the day the page was published;
 *  - the XML is kept in the cache for an hour and dropped when a page is published, unpublished, changed or deleted, or an SEO rule changes (SitemapInvalidator).
 */
final class SitemapBuilder
{
    private const KEY = 'itk_sitemap_';
    private const TTL = 3600;

    public function __construct(
        private readonly IndexablePages $pages,
        private readonly SettingsService $settings,
        private readonly CacheInterface $cache,
        private readonly ?PageVersions $versions = null,
        private readonly ?PublicUrl $publicUrl = null,
    ) {
    }

    /** The XML, or null when the site address is not set. */
    public function xml(): ?string
    {
        $base = $this->xmlBase();
        if (null === $base) {
            return null;
        }

        if ($this->isMultilingual()) {
            $sig = \hash('sha256', $base . "\0" . $this->pages->catalogSignature());
            $build = function (ItemInterface $item) use ($base, $sig): array {
                $item->expiresAfter(self::TTL);

                return ['sig' => $sig, 'xml' => $this->build($base)];
            };
            $key = self::KEY . 'locales';
            $kept = $this->cache->get($key, $build);
            if (!\is_array($kept) || ($kept['sig'] ?? null) !== $sig) {
                $this->cache->delete($key);
                $kept = $this->cache->get($key, $build);
            }

            return (string) $kept['xml'];
        }

        return $this->cache->get(self::KEY . \md5($base), function (ItemInterface $item) use ($base): string {
            $item->expiresAfter(self::TTL);

            return $this->build($base);
        });
    }

    /** Drops the kept XML (the next request builds it again). */
    public function forget(): void
    {
        $this->cache->delete(self::KEY . 'locales');
        $base = $this->xmlBase();
        if (null !== $base) {
            $this->cache->delete(self::KEY . \md5($base));
        }
    }

    /** Domain-only single-language XML retains the spelling shipped by 0.2.2; other consumers use canonical origins. */
    private function xmlBase(): ?string
    {
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

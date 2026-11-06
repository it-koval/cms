<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Support\Url;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * GET /sitemap.xml: the live pages of the main language, one <url> each, the same rule for every site.
 *  - the pages are the ones of IndexablePages: the main language only (so no address leads to a 404 or to a page of another language), without `noindex`;
 *  - addresses are absolute and made from the site address of «Настройки → Сайт» (setting `domain`), never from the Host of the request: a forged Host cannot put
 *    its own address into the file. Without the setting there is no sitemap (the route answers 404);
 *  - <lastmod> is the day the page was published;
 *  - the XML is kept in the cache for an hour and dropped when a page is published, unpublished, changed or deleted, or an SEO rule changes (SitemapInvalidator).
 */
final class SitemapBuilder
{
    private const KEY = 'itk_sitemap_';
    private const TTL = 3600;
    private const RESERVED_ZONE = '~(^|\.)(localhost|test|local|example|invalid|internal)$~';

    public function __construct(
        private readonly IndexablePages $pages,
        private readonly SettingsService $settings,
        private readonly CacheInterface $cache,
    ) {
    }

    /** The XML, or null when the site address is not set. */
    public function xml(): ?string
    {
        $base = $this->base();
        if (null === $base) {
            return null;
        }

        return $this->cache->get(self::KEY . \md5($base), function (ItemInterface $item) use ($base): string {
            $item->expiresAfter(self::TTL);

            return $this->build($base);
        });
    }

    /** Drops the kept XML (the next request builds it again). */
    public function forget(): void
    {
        $base = $this->base();
        if (null !== $base) {
            $this->cache->delete(self::KEY . \md5($base));
        }
    }

    /** `https://example.com` from the setting `domain` ("example.com", or with the scheme); null when it is empty or not an address. */
    public function base(): ?string
    {
        $domain = \rtrim(\trim((string) $this->settings->get('domain', null, '')), '/');
        if (1 !== \preg_match('~^(https?://)?[\p{L}\p{N}][\p{L}\p{N}.\-]*(:\d{1,5})?$~u', $domain)) {
            return null;
        }

        return \preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain;
    }

    /** base() with a Cyrillic host such as `сайт.рф` in punycode, the only form a URL may carry; null when there is no address. */
    public function asciiBase(): ?string
    {
        $base = $this->base();
        $parts = null === $base ? false : \parse_url($base);
        $host = \is_array($parts) && isset($parts['host']) ? \idn_to_ascii(\mb_strtolower($parts['host']), \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46) : false;
        if (false === $host) {
            return null;
        }

        return ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** Can a search engine reach this host? Not localhost, an IP address or a reserved zone (.test, .local, .example …). */
    public static function isPublicHost(string $host): bool
    {
        return false === \filter_var($host, \FILTER_VALIDATE_IP) && 1 !== \preg_match(self::RESERVED_ZONE, $host);
    }

    private function build(string $base): string
    {
        $rows = [];
        foreach ($this->pages->rows() as $page) {
            $loc = $base . Url::encodePath($page['url']);
            $rows[$loc] = ['loc' => $loc, 'lastmod' => $page['publishedAt']?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        }
        $rows = \array_values($rows);
        \usort($rows, static fn (array $a, array $b): int => $a['loc'] <=> $b['loc']);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($rows as $r) {
            $xml .= '  <url><loc>' . \htmlspecialchars($r['loc'], \ENT_XML1 | \ENT_QUOTES, 'UTF-8') . '</loc>'
                . (null !== $r['lastmod'] ? '<lastmod>' . $r['lastmod'] . '</lastmod>' : '') . "</url>\n";
        }

        return $xml . "</urlset>\n";
    }
}

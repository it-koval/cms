<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

use Itk\Cms\Content\SiteAddress;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Security\CspNonce;
use Itk\Cms\Support\Url;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The finished HTML of live pages, so that a repeated visit is answered without a render (PageController).
 * The key is the scheme, the host, the base URL and the fingerprint of PageValidators: whatever the fingerprint sees changes the key, so an edit shows at once and no event has to clear anything.
 * What the fingerprint cannot see (a site's own tables, the time inside a day, the cookies and headers of the request) is limited by the ttl of the option and by itk_cache() of the templates; a site that changes such data clears the pool itself: clear().
 * The pool is `itk_cms.page_cache` (framework.cache.pools), apart from the other caches of the site.
 * Only an answer 200 of a visitor without a session is kept, and only when it carries no cookie and the template has not forbidden it; other headers of the response are not kept.
 * The number of entries must not depend on what a stranger can invent, or he fills the disk of the pool: so a request never touches the cache when it has a query string (the fingerprint still includes it, for the tag),
 * a host or a port nobody vouched for (see vouches()), another spelling of the address than the one the page was saved with (see canonical()), or a Forwarded / X-Forwarded-Prefix header (it moves the base of every link of the page).
 * The pool is pruned of expired entries at most once in PRUNE_EVERY seconds, from put(): the keys change with every edit, so old entries are never asked for again, and a filesystem pool does not delete an expired file by itself.
 */
final class PageCache
{
    private const DEFAULT_CONTENT_TYPE = 'text/html; charset=UTF-8';
    private const PRUNE_EVERY = 600;
    private const PRUNE_MARKER = 'prune-at';

    public function __construct(
        #[Autowire(service: 'itk_cms.page_cache')]
        private readonly CacheItemPoolInterface $pool,
        #[Autowire(param: 'itk_cms.page_cache.enabled')]
        private readonly bool $enabled,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
        #[Autowire(param: 'itk_cms.page_cache.ttl')]
        private readonly int $ttl,
        private readonly ClockInterface $clock,
        private readonly SitemapBuilder $sitemap,
    ) {
    }

    /** True when the request neither reads nor writes the cache: the option is off, debug, Authorization, a visitor with a session, a CSP nonce, a method other than GET and HEAD, a query string, an untrusted host or port, a non-canonical address, or a Forwarded or X-Forwarded-Prefix header. */
    public function bypasses(Request $request, PageTranslation $page): bool
    {
        return !$this->enabled || $this->debug || !$request->isMethodCacheable() || $request->headers->has('Authorization') || $request->hasPreviousSession()
            || null !== CspNonce::fromRequest($request) || null !== $request->getQueryString()
            || $request->headers->has('X-Forwarded-Prefix') || $request->headers->has('Forwarded') || $request->getPathInfo() !== self::canonical($page) || !$this->vouches($request);
    }

    public function get(Request $request, string $fingerprint): ?Response
    {
        $item = $this->pool->getItem($this->key($request, $fingerprint));
        $value = $item->isHit() ? $item->get() : null;
        $entry = \is_array($value) ? PageCacheEntry::fromArray($value) : null;

        return null === $entry ? null : new Response($entry->html, $entry->status, ['Content-Type' => $entry->contentType]);
    }

    /**
     * @param int|false|null $directive what the template asked through itk_cache(): a number of seconds the entry may live at most, false = do not keep, null = nothing asked
     *
     * @return bool whether the answer was kept
     */
    public function put(Request $request, string $fingerprint, Response $response, int|false|null $directive): bool
    {
        $html = $response->getContent();
        if (false === $directive || !$request->isMethod('GET') || 200 !== $response->getStatusCode() || !\is_string($html) || [] !== $response->headers->getCookies() || $this->startedSession($request)) {
            return false; // a HEAD answer has no body to keep; a started session may have put its own token into the page
        }
        $entry = new PageCacheEntry($html, 200, $response->headers->get('Content-Type') ?? self::DEFAULT_CONTENT_TYPE, $this->clock->now()->getTimestamp());
        $item = $this->pool->getItem($this->key($request, $fingerprint));
        $item->set($entry->toArray())->expiresAfter(\min($this->ttl, $directive ?? $this->ttl));
        $saved = $this->pool->save($item);
        $this->prune();

        return $saved;
    }

    /** Forgets every kept page (and nothing else of the site's cache); false when the pool refused. */
    public function clear(): bool
    {
        return $this->pool->clear();
    }

    private function key(Request $request, string $fingerprint): string
    {
        return 'p' . \sha1($request->getScheme() . '|' . $request->getHttpHost() . '|' . $request->getBaseUrl() . '|' . $fingerprint);
    }

    /** The one address the page is kept under, as the request path reads when a browser follows the page's own link: the saved url with its own trailing slash or none, one leading slash, %-coded the way Url::encodePath() does. */
    private static function canonical(PageTranslation $page): string
    {
        return Url::encodePath(Url::spelling((string) ($page->published['url'] ?? '')));
    }

    /** Drops the expired entries of a pool that can, if the last time was long ago. A marker item of the pool itself tells when that was, so that the check costs one read. */
    private function prune(): void
    {
        if (!$this->pool instanceof PruneableInterface) {
            return;
        }
        $marker = $this->pool->getItem(self::PRUNE_MARKER);
        if ($marker->isHit()) {
            return;
        }
        $this->pool->save($marker->set(true)->expiresAfter(self::PRUNE_EVERY));
        $this->pool->prune();
    }

    /**
     * The host and the port are the site's own. The port is the default of the scheme (80, 443) or the one written in the site address, whoever vouched for the host: a stranger cannot make a new entry with a port of his own.
     * The host is vouched for by Symfony (trusted_hosts: it has already refused every other one) or, without trusted hosts, it is the host of the site address («Настройки → Сайт», `domain`) with the same port.
     */
    private function vouches(Request $request): bool
    {
        $site = $this->sitemap->address();
        $port = (int) $request->getPort();
        $explicit = $port === ($request->isSecure() ? 443 : 80) ? null : $port;
        if (null !== $explicit && $explicit !== $site?->port) {
            return false;
        }
        if ([] !== Request::getTrustedHosts()) {
            return true;
        }

        // a browser asks for an international name in ASCII (xn--…), the setting may hold it in letters: both are compared as SiteAddress puts them
        return null !== $site && $site->host === SiteAddress::fromUrl('http://' . $request->getHost())?->host && $site->port === $explicit;
    }

    private function startedSession(Request $request): bool
    {
        return $request->hasSession(true) && $request->getSession()->isStarted();
    }
}

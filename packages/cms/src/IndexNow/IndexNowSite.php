<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SiteAddress;
use Itk\Cms\Content\SitemapBuilder;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What IndexNow needs to know about this site: is it on, the address, and the key.
 * The key is derived from the address and the secret of the application, so nothing is stored and the site owner has nothing to copy:
 * it is the same on every server of the site, differs between sites, and changes only with the address or APP_SECRET.
 *
 *   toggle off                                       Off
 *   `domain` empty or not an address                 NoAddress
 *   localhost, an IP, .localhost .test .local …      NotPublic
 *   no symfony/http-client                           NoHttpClient
 *   otherwise                                        Ready
 *
 * @internal
 */
final class IndexNowSite
{
    public const SETTING = 'indexNow';

    private const LAST = 'itk_indexnow_last';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SitemapBuilder $sitemap,
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
        private readonly ?HttpClientInterface $http,
    ) {
    }

    public function state(): IndexNowState
    {
        if (!\filter_var($this->settings->get(self::SETTING, null, false), \FILTER_VALIDATE_BOOLEAN)) {
            return IndexNowState::Off;
        }
        $address = $this->sitemap->address();
        if (null === $address) {
            return IndexNowState::NoAddress;
        }
        if (!$address->isPublic()) {
            return IndexNowState::NotPublic;
        }

        return null === $this->http ? IndexNowState::NoHttpClient : IndexNowState::Ready;
    }

    /** The address of the site (SitemapBuilder::address()). Only after state() said there is one. */
    public function address(): SiteAddress
    {
        return $this->sitemap->address() ?? throw new \LogicException('The site has no address: check state() first.');
    }

    /** 32 hex characters, the form IndexNow asks for. Only after state() said there is an address. */
    public function key(): string
    {
        return \substr(\hash_hmac('sha256', 'itk-indexnow|' . $this->address()->origin(), $this->secret), 0, 32);
    }

    public function keyLocation(): string
    {
        return $this->address()->origin() . '/' . $this->key() . '.txt';
    }

    /** @param int $status the HTTP status, 0 when the request did not complete */
    public function recordSubmission(int $count, int $status, ?string $error = null): void
    {
        $item = $this->cache->getItem(self::LAST);
        $item->set(['at' => $this->clock->now()->format(\DATE_ATOM), 'count' => $count, 'status' => $status, 'error' => $error]);
        $this->cache->save($item);
    }

    /** @return array{at: string, count: int, status: int, error: ?string}|null */
    public function lastSubmission(): ?array
    {
        $last = $this->cache->getItem(self::LAST)->get();

        return \is_array($last) ? $last : null;
    }
}

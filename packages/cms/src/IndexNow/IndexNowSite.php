<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Psr\Cache\CacheItemPoolInterface;
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
    private const RESERVED_ZONE = '~(^|\.)(localhost|test|local|example|invalid|internal)$~';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SitemapBuilder $sitemap,
        private readonly CacheItemPoolInterface $cache,
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
        $host = $this->hostOrNull();
        if (null === $host) {
            return IndexNowState::NoAddress;
        }
        if (false !== \filter_var($host, \FILTER_VALIDATE_IP) || 1 === \preg_match(self::RESERVED_ZONE, $host)) {
            return IndexNowState::NotPublic;
        }

        return null === $this->http ? IndexNowState::NoHttpClient : IndexNowState::Ready;
    }

    /** `https://example.com` (SitemapBuilder::base()); a Cyrillic host such as `сайт.рф` in punycode, the only form a URL may carry. */
    public function base(): ?string
    {
        $base = $this->sitemap->base();
        $parts = null === $base ? false : \parse_url($base);
        $host = \is_array($parts) && isset($parts['host']) ? \idn_to_ascii(\mb_strtolower($parts['host']), \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46) : false;
        if (false === $host) {
            return null;
        }

        return ($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** `example.com`, without the port. Only after state() said there is an address. */
    public function host(): string
    {
        return $this->hostOrNull() ?? throw new \LogicException('The site has no address: check state() first.');
    }

    /** 32 hex characters, the form IndexNow asks for. Only after state() said there is an address. */
    public function key(): string
    {
        return \substr(\hash_hmac('sha256', 'itk-indexnow|' . $this->requireBase(), $this->secret), 0, 32);
    }

    public function keyLocation(): string
    {
        return $this->requireBase() . '/' . $this->key() . '.txt';
    }

    /** @param int $status the HTTP status, 0 when the request did not complete */
    public function recordSubmission(int $count, int $status, ?string $error = null): void
    {
        $item = $this->cache->getItem(self::LAST);
        $item->set(['at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'count' => $count, 'status' => $status, 'error' => $error]);
        $this->cache->save($item);
    }

    /** @return array{at: string, count: int, status: int, error: ?string}|null */
    public function lastSubmission(): ?array
    {
        $last = $this->cache->getItem(self::LAST)->get();

        return \is_array($last) ? $last : null;
    }

    private function hostOrNull(): ?string
    {
        $base = $this->base();
        $host = null === $base ? null : \parse_url($base, \PHP_URL_HOST);

        return \is_string($host) ? $host : null;
    }

    private function requireBase(): string
    {
        return $this->base() ?? throw new \LogicException('The site has no address: check state() first.');
    }
}

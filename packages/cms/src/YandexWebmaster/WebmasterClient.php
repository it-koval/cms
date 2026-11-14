<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster;

use Itk\Cms\YandexWebmaster\Model\WebmasterApiException;
use Itk\Cms\YandexWebmaster\Model\WebmasterUnreachableException;
use Itk\Cms\YandexWebmaster\Model\WebmasterVerification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls of the Yandex Webmaster API v4 the CMS makes, with the OAuth token of the site owner passed in by the caller (the client keeps nothing).
 * The token travels only in the Authorization header: it is in no URL, log line or exception message.
 * An error of Yandex is a WebmasterApiException, no answer at all is a WebmasterUnreachableException.
 *
 * @internal
 */
final class WebmasterClient
{
    /** Seconds without a byte, and in total for one call: the status screen waits for these calls. */
    private const TIMEOUT = 10;
    private const TOTAL = 20;
    /** The answers of «not yet»: Yandex has not loaded the site, or the site is not verified. They are no failure. */
    private const NOT_READY = ['HOST_NOT_LOADED', 'HOST_NOT_INDEXED', 'HOST_NOT_VERIFIED'];

    public function __construct(
        private readonly ?HttpClientInterface $http,
        #[Autowire(param: 'itk_cms.yandex_webmaster.api_base')]
        private readonly string $base,
    ) {
    }

    public function isAvailable(): bool
    {
        return null !== $this->http;
    }

    public function userId(string $token): int
    {
        $id = $this->call('GET', $token, '/user')['user_id'] ?? null;
        if (!\is_int($id)) {
            throw new WebmasterUnreachableException('Yandex Webmaster answered without user_id');
        }

        return $id;
    }

    /** @return list<array{id: string, url: string}> the host id and `ascii_host_url` of every host of the user */
    public function hosts(string $token, int $userId): array
    {
        $out = [];
        foreach (\is_array($hosts = $this->call('GET', $token, '/user/' . $userId . '/hosts')['hosts'] ?? null) ? $hosts : [] as $host) {
            if (\is_array($host) && \is_string($host['host_id'] ?? null) && \is_string($host['ascii_host_url'] ?? null)) {
                $out[] = ['id' => $host['host_id'], 'url' => $host['ascii_host_url']];
            }
        }

        return $out;
    }

    /** Adds the site; a host that is already there (409) gives its id as well. */
    public function addHost(string $token, int $userId, string $hostUrl): string
    {
        try {
            $body = $this->call('POST', $token, '/user/' . $userId . '/hosts', json: ['host_url' => $hostUrl]);
        } catch (WebmasterApiException $e) {
            if (409 !== $e->status || 'HOST_ALREADY_ADDED' !== $e->errorCode) {
                throw $e;
            }
            $body = $e->body;
        }
        $id = $body['host_id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new WebmasterUnreachableException('Yandex Webmaster answered without host_id');
        }

        return $id;
    }

    public function verification(string $token, int $userId, string $hostId): WebmasterVerification
    {
        return $this->verificationOf($this->call('GET', $token, $this->host($userId, $hostId) . '/verification'));
    }

    /** Asks Yandex to check the meta tag now. A check that is already running (409) is no error. */
    public function startVerification(string $token, int $userId, string $hostId): WebmasterVerification
    {
        try {
            return $this->verificationOf($this->call('POST', $token, $this->host($userId, $hostId) . '/verification', 'verification_type=META_TAG'));
        } catch (WebmasterApiException $e) {
            if (409 !== $e->status) {
                throw $e;
            }

            return new WebmasterVerification(WebmasterVerification::IN_PROGRESS, null, null);
        }
    }

    /** Adds the sitemap to the host; one that is already there (409) is fine. */
    public function addSitemap(string $token, int $userId, string $hostId, string $url): void
    {
        try {
            $this->call('POST', $token, $this->host($userId, $hostId) . '/user-added-sitemaps', json: ['url' => $url]);
        } catch (WebmasterApiException $e) {
            if (409 !== $e->status) {
                throw $e;
            }
        }
    }

    /** @return ?array{sqi: ?int, searchable: ?int, excluded: ?int, problems: array{FATAL: int, CRITICAL: int, POSSIBLE_PROBLEM: int, RECOMMENDATION: int}} null while Yandex has not loaded the site */
    public function summary(string $token, int $userId, string $hostId): ?array
    {
        try {
            $body = $this->call('GET', $token, $this->host($userId, $hostId) . '/summary');
        } catch (WebmasterApiException $e) {
            if ($this->isNotReady($e)) {
                return null;
            }
            throw $e;
        }
        $problems = \is_array($body['site_problems'] ?? null) ? $body['site_problems'] : [];

        return [
            'sqi' => self::int($body['sqi'] ?? null),
            'searchable' => self::int($body['searchable_pages_count'] ?? null),
            'excluded' => self::int($body['excluded_pages_count'] ?? null),
            'problems' => [
                'FATAL' => self::int($problems['FATAL'] ?? null) ?? 0,
                'CRITICAL' => self::int($problems['CRITICAL'] ?? null) ?? 0,
                'POSSIBLE_PROBLEM' => self::int($problems['POSSIBLE_PROBLEM'] ?? null) ?? 0,
                'RECOMMENDATION' => self::int($problems['RECOMMENDATION'] ?? null) ?? 0,
            ],
        ];
    }

    /**
     * The popular queries of the last week, the ten with most shows.
     *
     * @return ?array{from: ?string, to: ?string, rows: list<array{text: string, shows: ?int, clicks: ?int}>} null while Yandex has no data
     */
    public function popularQueries(string $token, int $userId, string $hostId): ?array
    {
        try {
            $body = $this->call('GET', $token, $this->host($userId, $hostId) . '/search-queries/popular', 'order_by=TOTAL_SHOWS&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS&limit=10');
        } catch (WebmasterApiException $e) {
            if ($this->isNotReady($e)) {
                return null;
            }
            throw $e;
        }
        $rows = [];
        foreach (\is_array($body['queries'] ?? null) ? $body['queries'] : [] as $q) {
            if (!\is_array($q) || !\is_string($q['query_text'] ?? null)) {
                continue;
            }
            $indicators = \is_array($q['indicators'] ?? null) ? $q['indicators'] : [];
            $rows[] = ['text' => $q['query_text'], 'shows' => self::int($indicators['TOTAL_SHOWS'] ?? null), 'clicks' => self::int($indicators['TOTAL_CLICKS'] ?? null)];
        }
        \usort($rows, static fn (array $a, array $b): int => ($b['shows'] ?? -1) <=> ($a['shows'] ?? -1));

        return ['from' => self::date($body['date_from'] ?? null), 'to' => self::date($body['date_to'] ?? null), 'rows' => \array_slice($rows, 0, 10)];
    }

    private function host(int $userId, string $hostId): string
    {
        return '/user/' . $userId . '/hosts/' . \rawurlencode($hostId);
    }

    private function isNotReady(WebmasterApiException $e): bool
    {
        return 404 === $e->status && \in_array($e->errorCode, self::NOT_READY, true);
    }

    /** @param array<string, mixed> $body */
    private function verificationOf(array $body): WebmasterVerification
    {
        $state = \is_string($body['verification_state'] ?? null) ? $body['verification_state'] : WebmasterVerification::NONE;
        $info = \is_array($body['fail_info'] ?? null) ? $body['fail_info'] : [];

        return new WebmasterVerification($state, \is_string($body['verification_uin'] ?? null) && '' !== $body['verification_uin'] ? $body['verification_uin'] : null, \is_string($info['reason'] ?? null) ? $info['reason'] : null);
    }

    /**
     * @param ?array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $token, string $path, string $query = '', ?array $json = null): array
    {
        if (null === $this->http) {
            throw new \LogicException('symfony/http-client is not installed: check WebmasterClient::isAvailable() first.');
        }
        $options = ['headers' => ['Authorization' => 'OAuth ' . $token, 'Accept' => 'application/json'], 'timeout' => self::TIMEOUT, 'max_duration' => self::TOTAL, 'max_redirects' => 0];
        if (null !== $json) {
            $options['json'] = $json;
        }
        try {
            $response = $this->http->request($method, \rtrim($this->base, '/') . $path . ('' === $query ? '' : '?' . $query), $options);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new WebmasterUnreachableException('Yandex Webmaster did not answer: ' . $e::class, 0, $e);
        }
        $body = \json_decode($raw, true);
        $body = \is_array($body) ? $body : [];
        if ($status >= 400 || $status < 200) {
            throw new WebmasterApiException($status, \is_string($body['error_code'] ?? null) ? $body['error_code'] : null, $body);
        }

        return $body;
    }

    private static function int(mixed $value): ?int
    {
        return \is_int($value) || \is_float($value) ? (int) \round($value) : null;
    }

    private static function date(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? \substr($value, 0, 10) : null;
    }
}

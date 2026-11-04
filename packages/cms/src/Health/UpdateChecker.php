<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * «Доступные обновления» of itk/cms. The source is `itk_cms.update_source`, an address that answers JSON: either {"version": "1.4.0"}
 * (also "latest" or "tag_name": GitHub's releases/latest works as is), or the Packagist metadata {"packages": {"itk/cms": [{"version": …}]}}.
 * Off by default (the package is not published yet). The answer is kept for 12 hours; a failed check is retried after 10 minutes.
 *
 * @internal
 */
final class UpdateChecker
{
    public const TTL = 43200;
    public const RETRY_AFTER = 600;
    private const KEY = 'itk_health_updates';

    public function __construct(
        #[Autowire('%itk_cms.update_source%')]
        private readonly ?string $source,
        private readonly ?HttpClientInterface $http,
        private readonly CacheInterface $cache,
    ) {
    }

    public function enabled(): bool
    {
        return null !== $this->source && '' !== \trim($this->source);
    }

    /**
     * @param ?string $current installed version («1.2.0», «dev-main»)
     *
     * @return array{enabled: bool, current: ?string, latest: ?string, hasUpdate: bool, checkedAt: ?string, message: string}
     */
    public function check(?string $current, bool $refresh = false): array
    {
        $base = ['enabled' => $this->enabled(), 'current' => $current, 'latest' => null, 'hasUpdate' => false, 'checkedAt' => null];
        if (!$this->enabled()) {
            return $base + ['message' => I18n::t('ops.update_unpublished')];
        }
        if (!\preg_match('~^https?://~i', \trim((string) $this->source))) {
            return $base + ['message' => I18n::t('ops.update_bad_source')];
        }
        if (null === $this->http) { // symfony/http-client is a suggestion, not a requirement
            return $base + ['message' => I18n::t('ops.update_needs_http_client')];
        }
        if ($refresh) {
            $this->cache->delete(self::KEY);
        }
        /** @var array{latest: ?string, checkedAt: string, error: bool} $found */
        $found = $this->cache->get(self::KEY, function (ItemInterface $item): array {
            $latest = $this->fetch();
            $item->expiresAfter(null === $latest ? self::RETRY_AFTER : self::TTL);

            return ['latest' => $latest, 'checkedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'error' => null === $latest];
        });
        $base['checkedAt'] = $found['checkedAt'];
        if ($found['error'] || null === $found['latest']) {
            return $base + ['message' => I18n::t('ops.update_check_failed')];
        }
        $latest = $found['latest'];
        $base['latest'] = $latest;
        $installed = null === $current ? null : \ltrim($current, 'vV');
        if (null === $installed || !\preg_match('/^\d/', $installed)) {
            return $base + ['message' => I18n::t('ops.update_dev_version', ['latest' => $latest])];
        }
        if (\version_compare($latest, $installed, '>')) {
            return ['hasUpdate' => true] + $base + ['message' => I18n::t('ops.update_available', ['latest' => $latest])];
        }

        return $base + ['message' => I18n::t('ops.update_latest')];
    }

    private function fetch(): ?string
    {
        try {
            $data = $this->http?->request('GET', \trim((string) $this->source), ['timeout' => 5, 'max_duration' => 8, 'headers' => ['Accept' => 'application/json']])->toArray();
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data)) {
            return null;
        }
        $versions = [];
        foreach (['version', 'latest', 'tag_name', 'tag'] as $k) {
            if (isset($data[$k]) && \is_string($data[$k])) {
                $versions[] = $data[$k];
            }
        }
        foreach ((array) ($data['packages']['itk/cms'] ?? []) as $entry) {
            if (\is_array($entry) && isset($entry['version']) && \is_string($entry['version'])) {
                $versions[] = $entry['version'];
            }
        }
        $best = null;
        foreach ($versions as $v) {
            $v = \ltrim(\trim($v), 'vV');
            if (\preg_match('/^\d+(\.\d+){0,3}$/', $v) && (null === $best || \version_compare($v, $best, '>'))) { // only releases: «1.4.0-beta» and «dev-main» are skipped
                $best = $v;
            }
        }

        return $best;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Analytics;

use Symfony\Component\Asset\PackageInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** @internal The loader published by Symfony's assets:install. */
final class AnalyticsAsset
{
    public const PATH = 'bundles/itkcms/analytics.js';

    private int|false|null $modifiedAt = null;
    private string $version = '0';

    public function __construct(
        private readonly ?RequestStack $requests = null,
        // Symfony Asset is optional in a site. The bundle file has its own version and is outside the site's asset manifest.
        private readonly ?PackageInterface $assetPackage = null,
        private readonly string $file = __DIR__ . '/../../public/analytics.js',
    ) {
    }

    public function url(): string
    {
        $url = $this->assetPackage?->getUrl(self::PATH)
            ?? ($this->requests?->getMainRequest()?->getBasePath() ?? '') . '/' . self::PATH;

        return $url . '?v=' . $this->version();
    }

    public function version(): string
    {
        \clearstatcache(true, $this->file);
        $modifiedAt = @\filemtime($this->file);
        if ($modifiedAt !== $this->modifiedAt) {
            $hash = false === $modifiedAt ? false : @\hash_file('sha256', $this->file);
            $this->modifiedAt = $modifiedAt;
            $this->version = false === $hash ? '0' : \substr($hash, 0, 16);
        }

        return $this->version;
    }
}

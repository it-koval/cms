<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Admin\BundleAssets;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;

final class BundleAssetsPackageTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', ['assets' => $options['assets']]);
    }

    #[Test]
    public function bundleUrlsKeepCdnAndBasePathsWithoutSiteVersioningOrManifestLookups(): void
    {
        if (!\class_exists(Packages::class)) {
            self::markTestSkipped('Symfony Asset is an optional site component.');
        }
        $manifest = \tempnam(__DIR__ . '/../App', 'itk-assets-');
        \file_put_contents($manifest, '{"build/app.js":"build/app.123.js"}');
        try {
            foreach ([
                [['base_urls' => ['https://cdn.example.test/static'], 'version' => 'v1'], 'https://cdn.example.test/static'],
                [['base_urls' => ['https://cdn.example.test/static'], 'json_manifest_path' => $manifest, 'strict_mode' => true], 'https://cdn.example.test/static'],
                [['base_path' => '/static', 'version' => 'v1'], '/static'],
            ] as [$config, $base]) {
                self::bootKernel(['assets' => $config]);
                $requests = self::getContainer()->get(RequestStack::class);
                $requests->push(Request::create('https://site.example.test/admin'));
                $assets = self::getContainer()->get(BundleAssets::class);
                self::assertSame($base . '/bundles/itkcms/preview.js', $assets->url('preview.js'));
                self::assertSame($base . '/bundles/itkcms/admin/', $assets->url('admin/'), 'plugins receive a usable directory prefix');
                self::ensureKernelShutdown();
            }
        } finally {
            \unlink($manifest);
        }
    }
}

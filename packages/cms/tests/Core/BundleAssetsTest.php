<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Admin\BundleAssets;
use Itk\Cms\Admin\Theme\AdminFont;
use Itk\Cms\Controller\Admin\AdminAppController;
use Itk\Cms\Twig\ItkExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Context\RequestStackContext;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\UrlPackage;
use Symfony\Component\Asset\VersionStrategy\StaticVersionStrategy;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class BundleAssetsTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if (null !== $this->dir) {
            (new Filesystem())->remove($this->dir);
        }
    }

    private function request(string $base): Request
    {
        return Request::create('https://example.test' . $base . '/admin', 'GET', [], [], [], [
            'SCRIPT_NAME' => $base . '/index.php', 'SCRIPT_FILENAME' => '/public/index.php', 'PHP_SELF' => $base . '/index.php',
        ]);
    }

    private function font(BundleAssets $assets): AdminFont
    {
        $this->dir = \dirname(__DIR__, 2) . '/.phpunit.cache/f2-assets-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir . '/.vite', 0775, true);
        \file_put_contents($this->dir . '/.vite/manifest.json', \json_encode([
            'font/golos-text-cyrillic-wght-normal.woff2' => ['file' => 'assets/font.woff2'],
        ], \JSON_THROW_ON_ERROR));

        return new AdminFont($this->dir, $assets);
    }

    #[Test]
    public function requestDirectoriesAffectManifestFontAndMarkersWithoutAnAssetDependency(): void
    {
        $requests = new RequestStack();
        $assets = new BundleAssets($requests);
        $font = $this->font($assets);
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ItkCms');
        $twig = new Environment($loader);
        $twig->addExtension(new ItkExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([BundleAssets::class => static fn (): BundleAssets => $assets]));
        foreach (['/sub', '/second'] as $base) {
            $requests->push($this->request($base));
            try {
                self::assertSame($base, $requests->getCurrentRequest()->getBasePath());
                self::assertSame($base . '/bundles/itkcms/preview.js', $assets->url('preview.js'));
                self::assertStringContainsString('url(' . $base . '/bundles/itkcms/admin/assets/font.woff2)', $font->css());
                $manifest = AdminAppController::manifestAssets(['index.html' => ['file' => 'assets/index.js', 'css' => ['assets/main.css']]], 'kk', static fn (string $file): string => $assets->url('admin/' . $file));
                self::assertSame($base . '/bundles/itkcms/admin/assets/index.js', $manifest['js']);
                self::assertSame([$base . '/bundles/itkcms/admin/assets/main.css'], $manifest['css']);
                $markers = $twig->render('@ItkCms/preview/_markers.html.twig', ['itk_preview' => true]);
                self::assertStringContainsString('href="' . $base . '/bundles/itkcms/preview.css"', $markers);
                self::assertStringContainsString('src="' . $base . '/bundles/itkcms/preview.js"', $markers);
            } finally {
                $requests->pop();
            }
        }
        self::assertSame('/bundles/itkcms/preview.js', $assets->url('preview.js'));
    }

    #[Test]
    public function symfonyAssetBuildsVersionedUrlsForBothSubdirectoriesAndCdn(): void
    {
        if (!\class_exists(Packages::class)) {
            self::markTestSkipped('Symfony Asset is an optional site component.');
        }
        $requests = new RequestStack();
        $requests->push($this->request('/sub'));
        // @phpstan-ignore class.notFound (symfony/asset is optional; the test is skipped without it.)
        $version = new StaticVersionStrategy('f2');
        // @phpstan-ignore class.notFound (symfony/asset is optional; the test is skipped without it.)
        $context = new RequestStackContext($requests);
        // @phpstan-ignore class.notFound (symfony/asset is optional; the test is skipped without it.)
        $path = new BundleAssets($requests, new Packages(new PathPackage('', $version, $context)));
        self::assertSame('/sub/bundles/itkcms/preview.js?f2', $path->url('preview.js'));
        // @phpstan-ignore class.notFound (symfony/asset is optional; the test is skipped without it.)
        $cdn = new BundleAssets($requests, new Packages(new UrlPackage('https://cdn.example.test/static', $version)));
        self::assertSame('https://cdn.example.test/static/bundles/itkcms/preview.js?f2', $cdn->url('preview.js'));
        self::assertStringContainsString('url(https://cdn.example.test/static/bundles/itkcms/admin/assets/font.woff2?f2)', $this->font($cdn)->css());
    }
}

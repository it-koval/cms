<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Controller\Admin\AdminAppController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** What the shell of the admin loads ahead of the entry: the code of the app and the catalogues the admin asks for first, whatever Vite calls them in the manifest. */
final class AdminShellAssetsTest extends TestCase
{
    private const BASE = AdminAppController::ASSETS_URL . 'assets/';

    /**
     * A language of several files: the chunk holds more than one module, so its key in the manifest is made of its name.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function manifest(): array
    {
        return [
            'index.html' => ['file' => 'assets/index-a.js', 'isEntry' => true, 'css' => ['assets/index-a.css'], 'imports' => ['_vue-a.js']],
            '_vue-a.js' => ['file' => 'assets/vue-a.js', 'name' => 'vue'],
            'src/start.js' => ['file' => 'assets/start-a.js', 'name' => 'start', 'css' => ['assets/start-a.css'], 'imports' => ['_vue-a.js', '_shared-a.js']],
            '_shared-a.js' => ['file' => 'assets/shared-a.js', 'name' => 'shared'],
            '_i18n-en-x.js' => ['file' => 'assets/i18n-en-x.js', 'name' => 'i18n-en'],
            '_i18n-ru-x.js' => ['file' => 'assets/i18n-ru-x.js', 'name' => 'i18n-ru'],
            '_i18n-kk-x.js' => ['file' => 'assets/i18n-kk-x.js', 'name' => 'i18n-kk'],
            'src/views/PagesView.vue' => ['file' => 'assets/PagesView-a.js', 'name' => 'PagesView', 'isDynamicEntry' => true],
        ];
    }

    /** @return list<string> */
    private static function catalogues(string $locale): array
    {
        $assets = AdminAppController::manifestAssets(self::manifest(), $locale);
        self::assertNotNull($assets);

        return \array_values(\array_filter($assets['preload'], static fn (string $url): bool => \str_contains($url, '/i18n-')));
    }

    #[Test]
    public function theLanguageOfThePersonAndTheFallbackArePreloaded(): void
    {
        self::assertEqualsCanonicalizing([self::BASE . 'i18n-en-x.js', self::BASE . 'i18n-kk-x.js'], self::catalogues('kk'));
        self::assertEqualsCanonicalizing([self::BASE . 'i18n-en-x.js', self::BASE . 'i18n-ru-x.js'], self::catalogues('ru'));
    }

    #[Test]
    public function theFallbackIsPreloadedOnce(): void
    {
        self::assertSame([self::BASE . 'i18n-en-x.js'], self::catalogues('en'));
    }

    #[Test]
    public function aLanguageOfOneFileIsFoundByTheSameName(): void
    {
        $assets = AdminAppController::manifestAssets([
            'index.html' => ['file' => 'assets/index-a.js', 'isEntry' => true],
            'src/i18n/en/i18n.json' => ['file' => 'assets/i18n-en-x.js', 'name' => 'i18n-en'],
            'src/i18n/kk/i18n.json' => ['file' => 'assets/i18n-kk-x.js', 'name' => 'i18n-kk'],
            'src/i18n/ru/i18n.json' => ['file' => 'assets/i18n-ru-x.js', 'name' => 'i18n-ru'],
        ], 'kk');

        self::assertNotNull($assets);
        self::assertEqualsCanonicalizing([self::BASE . 'i18n-en-x.js', self::BASE . 'i18n-kk-x.js'], $assets['preload']);
    }

    #[Test]
    public function theCodeOfTheAppComesWithItsImportsAndStylesButNotTheScreens(): void
    {
        $assets = AdminAppController::manifestAssets(self::manifest(), 'ru');

        self::assertNotNull($assets);
        self::assertSame(self::BASE . 'index-a.js', $assets['js']);
        self::assertSame([self::BASE . 'start-a.css', self::BASE . 'index-a.css'], $assets['css'], 'the styles of the app come ahead of the global ones of the entry');
        self::assertContains(self::BASE . 'start-a.js', $assets['preload']);
        self::assertContains(self::BASE . 'shared-a.js', $assets['preload']);
        self::assertContains(self::BASE . 'vue-a.js', $assets['preload']);
        self::assertNotContains(self::BASE . 'PagesView-a.js', $assets['preload']);
        self::assertNotContains(self::BASE . 'index-a.js', $assets['preload']);
    }

    #[Test]
    public function aManifestWithoutAnEntryGivesNothing(): void
    {
        self::assertNull(AdminAppController::manifestAssets(['_x.js' => ['file' => 'assets/x.js']], 'ru'));
    }
}

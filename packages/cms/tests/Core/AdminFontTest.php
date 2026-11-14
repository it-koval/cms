<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Admin\Theme\AdminFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Правила @font-face для страниц входа: что получается из манифеста сборки и что, когда сборки или шрифта в ней нет. */
final class AdminFontTest extends TestCase
{
    private const CYRILLIC_EXT = 'node_modules/@fontsource-variable/golos-text/files/golos-text-cyrillic-ext-wght-normal.woff2';
    private const CYRILLIC = 'node_modules/@fontsource-variable/golos-text/files/golos-text-cyrillic-wght-normal.woff2';
    private const LATIN = 'node_modules/@fontsource-variable/golos-text/files/golos-text-latin-wght-normal.woff2';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/itk-font-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.vite/manifest.json', '/manifest.json'] as $name) {
            if (\is_file($this->dir . $name)) {
                \unlink($this->dir . $name);
            }
        }
        \rmdir($this->dir . '/.vite');
        \rmdir($this->dir);
    }

    /** @param array<string, mixed> $manifest */
    private function write(array $manifest, string $name = '/.vite/manifest.json'): void
    {
        \file_put_contents($this->dir . $name, \json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function withoutABuildThereAreNoRules(): void
    {
        self::assertSame('', (new AdminFont($this->dir))->css());
    }

    #[Test]
    public function aBuildWithoutTheFontGivesNoRules(): void
    {
        $this->write(['index.html' => ['file' => 'assets/index-abc.js', 'isEntry' => true]]);

        self::assertSame('', (new AdminFont($this->dir))->css());
    }

    #[Test]
    public function aManifestThatCannotBeReadGivesNoRules(): void
    {
        \file_put_contents($this->dir . '/.vite/manifest.json', '{не json');

        self::assertSame('', (new AdminFont($this->dir))->css());
    }

    #[Test]
    public function theAddressesComeFromTheManifestOfTheBuild(): void
    {
        $this->write([self::CYRILLIC_EXT => ['file' => 'assets/ext-xyz.woff2'], self::CYRILLIC => ['file' => 'assets/cyr-abc.woff2'], self::LATIN => ['file' => 'assets/lat-def.woff2']]);

        $css = (new AdminFont($this->dir))->css();

        self::assertStringContainsString("font-family:'" . AdminFont::FAMILY . "'", $css);
        self::assertStringContainsString('url(/bundles/itkcms/admin/assets/cyr-abc.woff2)', $css);
        self::assertStringContainsString('url(/bundles/itkcms/admin/assets/lat-def.woff2)', $css);
        self::assertStringContainsString('url(/bundles/itkcms/admin/assets/ext-xyz.woff2)', $css);
        self::assertSame(3, \substr_count($css, '@font-face'));
    }

    #[Test]
    public function everyKazakhLetterIsCoveredByAFace(): void
    {
        $this->write([self::CYRILLIC_EXT => ['file' => 'assets/ext-xyz.woff2'], self::CYRILLIC => ['file' => 'assets/cyr-abc.woff2'], self::LATIN => ['file' => 'assets/lat-def.woff2']]);

        $css = (new AdminFont($this->dir))->css();
        \preg_match_all('~unicode-range:([^}]+)\}~', $css, $ranges);
        $covered = static function (int $code) use ($ranges): bool {
            foreach ($ranges[1] as $list) {
                foreach (\explode(',', $list) as $range) {
                    [$from, $to] = \array_pad(\explode('-', \substr($range, 2), 2), 2, null);
                    if ($code >= \hexdec($from) && $code <= \hexdec($to ?? $from)) {
                        return true;
                    }
                }
            }

            return false;
        };

        foreach (\mb_str_split('ҚқҒғҢңӨөҮүҰұӘәҺһ') as $letter) {
            self::assertTrue($covered(\mb_ord($letter)), $letter . ' (U+' . \strtoupper(\dechex(\mb_ord($letter))) . ') is not covered by any face');
        }
        // The extended face is the one of @fontsource-variable/golos-text, the plain Cyrillic face does not hold most of these letters.
        self::assertStringContainsString("src:url(/bundles/itkcms/admin/assets/ext-xyz.woff2) format('woff2-variations');unicode-range:U+0460-052F,U+1C80-1C8A,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F}", $css);
    }

    #[Test]
    public function theManifestOfAnOlderViteIsReadToo(): void
    {
        $this->write([self::LATIN => ['file' => 'assets/lat-def.woff2']], '/manifest.json');

        self::assertStringContainsString('url(/bundles/itkcms/admin/assets/lat-def.woff2)', (new AdminFont($this->dir))->css());
    }

    #[Test]
    public function aFileNameThatCouldBreakOutOfTheStyleIsLeftOut(): void
    {
        $this->write([self::CYRILLIC => ['file' => 'assets/x)}</style>.woff2'], self::LATIN => ['file' => '../secret.woff2']]);

        self::assertSame('', (new AdminFont($this->dir))->css());
    }
}

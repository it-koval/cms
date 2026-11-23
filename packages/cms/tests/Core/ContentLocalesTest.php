<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentLocalesTest extends TestCase
{
    #[Test]
    public function looksUpNamesPrefixesAndLanguageMetadata(): void
    {
        $locales = new ContentLocales('zh', [
            'kk' => ['name' => 'Kazakh', 'og_locale' => 'kk_KZ'],
            'zh' => ['name' => 'Chinese', 'html_lang' => 'zh-CN'],
            'en' => ['name' => 'English'],
            'sr' => ['name' => 'Serbian', 'html_lang' => 'sr-Latn'],
        ]);

        self::assertTrue($locales->isConfigured());
        self::assertSame('zh', $locales->main());
        self::assertSame(['zh', 'kk', 'en', 'sr'], $locales->codes());
        self::assertTrue($locales->has('kk'));
        self::assertFalse($locales->has('zz'));
        self::assertSame('Chinese', $locales->name('zh'));
        self::assertSame('', $locales->prefix('zh'));
        self::assertSame('/kk', $locales->prefix('kk'));
        self::assertSame('zh-CN', $locales->htmlLang('zh'));
        self::assertSame('en', $locales->htmlLang('en'));
        self::assertSame('zh_CN', $locales->ogLocale('zh'));
        self::assertSame('kk_KZ', $locales->ogLocale('kk'));
        self::assertNull($locales->ogLocale('en'));
        self::assertNull($locales->ogLocale('sr'));
    }

    #[Test]
    public function listsTheMainLanguageFirstWithSiteDisplayNames(): void
    {
        $locales = new ContentLocales('ru', [
            'kk' => ['name' => 'Kazakh'],
            'ru' => ['name' => 'Russian'],
            'en' => ['name' => 'English'],
        ]);

        self::assertSame(['main' => 'ru', 'list' => [
            ['code' => 'ru', 'name' => 'Russian'],
            ['code' => 'kk', 'name' => 'Kazakh'],
            ['code' => 'en', 'name' => 'English'],
        ]], $locales->catalog());
        self::assertSame(['main' => 'en', 'list' => [['code' => 'en', 'name' => 'English']]], (new ContentLocales('en'))->catalog());
    }

    #[Test]
    public function findsTheLanguageFromTheFirstPathSegment(): void
    {
        $locales = self::multilingual();

        foreach (['/kk', '/kk/', '/kk/about', '/KK/about'] as $path) {
            self::assertSame('kk', $locales->fromPath($path), $path);
        }
        foreach (['/kk-news', '', '/', '/about', '/zz', '/about/kk'] as $path) {
            self::assertSame('ru', $locales->fromPath($path), $path);
        }
    }

    #[Test]
    public function anExplicitListRestrictsContentInputEvenWithOneLanguage(): void
    {
        $locales = new ContentLocales('ru', ['ru' => ['name' => 'Russian']]);

        self::assertTrue($locales->isConfigured());
        self::assertSame('ru', $locales->assertUsable(null));
        self::assertSame('ru', $locales->assertUsable(''));
        self::assertSame('ru', $locales->assertUsable('ru'));
        self::assertNull($locales->addressProblem('ru', '/kk/x'));

        $this->expectException(ContentException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage(I18n::t('content.locale_unknown', ['locale' => 'zz']));
        $locales->assertUsable('zz');
    }

    #[Test]
    public function aSiteWithoutTheListAllowsHistoricalLanguageInputs(): void
    {
        $locales = new ContentLocales('en');

        self::assertFalse($locales->isConfigured());
        self::assertSame(['en'], $locales->codes());
        self::assertSame('English', $locales->name('en'));
        self::assertSame('xyz-custom', (new ContentLocales('xyz-custom'))->name('xyz-custom'));
        self::assertSame('en', $locales->assertUsable(null));
        self::assertSame('en', $locales->assertUsable(''));
        self::assertSame('pt-BR', $locales->assertUsable('pt-BR'));
        self::assertSame('en', $locales->fromPath('/kk/about'));
        self::assertNull($locales->addressProblem('kk', '/about'));

        $this->expectException(ContentException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage(I18n::t('http.no_such_language'));
        $locales->assertUsable('kk/news');
    }

    #[Test]
    public function checksPrefixesOnTheComparableAddress(): void
    {
        $locales = self::multilingual();

        foreach (['/kk', '/kk/', '/kk/about', ' /KK/ABOUT/ '] as $url) {
            self::assertNull($locales->addressProblem('kk', $url), $url);
        }
        foreach (['/kk-news', '/about', '/en/about', '/'] as $url) {
            self::assertSame('content.url_locale_prefix', $locales->addressProblem('kk', $url), $url);
        }
        foreach (['/kk', '/kk/x', '/KK/', '/en/x'] as $url) {
            self::assertSame('content.url_other_locale_prefix', $locales->addressProblem('ru', $url), $url);
        }
        foreach (['/', '/about', '/kk-news', '/ru/news', '/zz'] as $url) {
            self::assertNull($locales->addressProblem('ru', $url), $url);
        }
    }

    private static function multilingual(): ContentLocales
    {
        return new ContentLocales('ru', ['ru' => ['name' => 'Russian'], 'kk' => ['name' => 'Kazakh'], 'en' => ['name' => 'English']]);
    }
}

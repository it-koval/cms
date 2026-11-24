<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Content\PageVersions;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\SeoRule;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Repository\SeoRuleRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class PageVersionsTest extends TestCase
{
    #[Test]
    public function liveVersionsFollowTheCatalogAndKeepTheirCanonicalPathsAndIndexability(): void
    {
        $locales = new ContentLocales('ru', [
            'kk' => ['name' => 'Қазақша'],
            'tr' => ['name' => 'Türkçe'],
            'ru' => ['name' => 'Русский'],
            'en' => ['name' => 'English'],
        ]); // i18n-ignore: configured language names
        $rule = new SeoRule('/kk/Қала//Бет/');
        $rule->locale = 'kk';
        $rule->robots = 'noindex,follow';
        $rules = $this->createMock(SeoRuleRepository::class);
        $rules->expects(self::once())->method('findActive')->willReturn([$rule]);
        $translations = $this->createMock(PageTranslationRepository::class);
        $translations->expects(self::never())->method('findAllLive');
        $indexable = new IndexablePages($translations, new SeoResolver($rules, new RequestStack(), 'ru'), 'ru');

        $page = new Page();
        $this->version($page, 'en', '/en/about', ['robots' => 'noindex']);
        $this->version($page, 'tr', '/tr/about')->status = PageTranslation::DRAFT;
        $this->version($page, 'zh', '/zh/about');
        $this->version($page, 'kk', '/kk/Қала//Бет/');
        $this->version($page, 'ru', '/about');

        self::assertSame([
            ['code' => 'ru', 'name' => 'Русский', 'url' => '/about', 'path' => '/about', 'noindex' => false], // i18n-ignore: configured language name
            ['code' => 'kk', 'name' => 'Қазақша', 'url' => '/kk/%D2%9A%D0%B0%D0%BB%D0%B0//%D0%91%D0%B5%D1%82/', 'path' => '/kk/Қала//Бет/', 'noindex' => true],
            ['code' => 'en', 'name' => 'English', 'url' => '/en/about', 'path' => '/en/about', 'noindex' => true],
        ], (new PageVersions($locales, $indexable))->live($page));
    }

    /** @param array<string, mixed> $seo */
    private function version(Page $page, string $locale, string $path, array $seo = []): PageTranslation
    {
        $version = new PageTranslation($page, $locale);
        $version->published = ['url' => $path, 'seo' => $seo];
        $version->status = PageTranslation::PUBLISHED;
        $page->translations->set($locale, $version);

        return $version;
    }
}

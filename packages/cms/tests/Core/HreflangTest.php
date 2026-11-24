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
use Itk\Cms\Twig\SeoRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** Pure fixtures: no kernel or database connection. */
final class HreflangTest extends TestCase
{
    private function locales(): ContentLocales
    {
        return new ContentLocales('ru', ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'], 'en' => ['name' => 'English']]); // i18n-ignore: configured language names
    }

    private function resolver(): SeoResolver
    {
        $rules = $this->createStub(SeoRuleRepository::class);
        $rules->method('findActive')->willReturn([]);

        return new SeoResolver($rules, new RequestStack(), 'ru');
    }

    private function versions(?ContentLocales $locales = null): PageVersions
    {
        $translations = $this->createMock(PageTranslationRepository::class);
        $translations->expects(self::never())->method('findAllLive');

        return new PageVersions($locales ?? $this->locales(), new IndexablePages($translations, $this->resolver(), 'ru', $locales ?? $this->locales()));
    }

    private function page(): Page
    {
        $page = new Page();
        foreach (['zh' => '/zh/test-hreflang-about', 'tr' => '/tr/test-hreflang-about', 'kk' => '/kk/test-hreflang-about', 'ru' => '/test-hreflang-about'] as $locale => $url) {
            $t = new PageTranslation($page, $locale);
            $t->published = ['url' => $url, 'title' => $locale, 'seo' => []];
            $t->publishedUrl = $url;
            $t->status = 'tr' === $locale ? PageTranslation::DRAFT : PageTranslation::PUBLISHED;
            $page->translations->set($locale, $t);
        }

        return $page;
    }

    #[Test]
    public function reciprocalAlternatesFollowCatalogUseHtmlTagsAndRequireTwoIndexableVersionsAndABase(): void
    {
        $page = $this->page();
        $versions = $this->versions();
        self::assertSame([
            ['hreflang' => 'ru', 'href' => 'https://example.com/test-hreflang-about'],
            ['hreflang' => 'kk', 'href' => 'https://example.com/kk/test-hreflang-about'],
            ['hreflang' => 'zh-CN', 'href' => 'https://example.com/zh/test-hreflang-about'],
            ['hreflang' => 'x-default', 'href' => 'https://example.com/test-hreflang-about'],
        ], $versions->alternates($page, 'https://example.com'));
        self::assertSame([], $versions->alternates($page, null));
        $page->translation('kk')->published['seo']['robots'] = 'noindex,follow';
        self::assertSame(['ru', 'zh-CN', 'x-default'], \array_column($versions->alternates($page, 'https://example.com'), 'hreflang'));
        $page->translation('ru')->published['seo']['robots'] = 'noindex';
        self::assertSame([], $versions->alternates($page, 'https://example.com'));
        $page->translation('kk')->published['seo'] = [];
        $this->assertOnlySecondaryAlternates($versions, $page);
        $page->translations->remove('ru');
        $this->assertOnlySecondaryAlternates($versions, $page);
        self::assertSame([], $this->versions(new ContentLocales('ru'))->alternates($page, 'https://example.com'));
    }

    private function assertOnlySecondaryAlternates(PageVersions $versions, Page $page): void
    {
        self::assertSame(['kk', 'zh-CN'], \array_column($versions->alternates($page, 'https://example.com'), 'hreflang'));
    }

    #[Test]
    public function rulesAndDisabledLanguagesAreExcludedFromSeoButRemainInThePublishedSwitcher(): void
    {
        $page = $this->page();
        $disabled = new PageTranslation($page, 'de');
        $disabled->published = ['url' => '/de/test-hreflang-about', 'seo' => []];
        $disabled->publishedUrl = '/de/test-hreflang-about';
        $disabled->status = PageTranslation::PUBLISHED;
        $page->translations->set('de', $disabled);
        $rule = new SeoRule('/kk/test-hreflang-about');
        $rule->locale = 'kk';
        $rule->robots = 'noindex,follow';
        $rules = $this->createStub(SeoRuleRepository::class);
        $rules->method('findActive')->willReturn([$rule]);
        $repo = $this->createStub(PageTranslationRepository::class);
        $versions = new PageVersions($this->locales(), new IndexablePages($repo, new SeoResolver($rules, new RequestStack(), 'ru'), 'ru', $this->locales()));
        self::assertSame(['ru', 'kk', 'zh'], \array_column($versions->live($page), 'code'));
        self::assertSame(['ru', 'zh-CN', 'x-default'], \array_column($versions->alternates($page, 'https://example.com'), 'hreflang'));
        self::assertSame([], $versions->alternates($page, 'invalid base'));
    }

    #[Test]
    public function runtimeUsesPrecomputedPageContextAndPreservesExplicitCanonicals(): void
    {
        $runtime = new SeoRuntime($this->resolver());
        $page = ['id' => 'page-id', 'title' => 'About', 'url' => '/test-hreflang-about', 'seo' => ['rule' => null], 'seo_alternates' => $this->versions()->alternates($this->page(), 'https://example.com'), 'seo_canonical' => 'https://example.com/test-hreflang-about'];
        $meta = $runtime->seo(['page' => $page]);
        self::assertSame($page['seo_alternates'], $meta['alternates']);
        self::assertSame('https://example.com/test-hreflang-about', $meta['canonical']);
        self::assertSame('https://canonical.example/test-hreflang-about', $runtime->seo(['page' => $page], ['canonical' => 'https://canonical.example/test-hreflang-about'])['canonical']);
        $page['seo']['canonical'] = 'https://explicit.example/test-hreflang-about';
        self::assertSame('https://explicit.example/test-hreflang-about', $runtime->seo(['page' => $page])['canonical']);
        self::assertSame([], $runtime->seo(['page' => $page, 'itk_preview' => true])['alternates']);
        $page['seo']['robots'] = 'noindex';
        self::assertSame([], $runtime->seo(['page' => $page])['alternates']);
        unset($page['id']);
        self::assertSame([], $runtime->seo(['page' => $page])['alternates']);
        self::assertSame('', $runtime->seo([])['canonical']);
    }

    #[Test]
    public function stockAndCustomLayoutsCanPrintTheSameLinksWhileLegacyHeadStaysUnchanged(): void
    {
        $runtime = new SeoRuntime($this->resolver());
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../templates', 'ItkCms');
        $loader->addPath(__DIR__ . '/../Fixtures/seo');
        $twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('itk_seo', [$runtime, 'seo'], ['needs_context' => true]));
        $page = ['id' => 'page-id', 'locale' => 'ru', 'title' => 'About', 'seo' => ['rule' => null], 'seo_alternates' => $this->versions()->alternates($this->page(), 'https://example.com'), 'seo_canonical' => 'https://example.com/test-hreflang-about'];
        $html = $twig->render('@ItkCms/layout.html.twig', ['page' => $page]);
        self::assertSame(4, \substr_count($html, 'rel="alternate"'));
        self::assertStringContainsString('hreflang="zh-CN" href="https://example.com/zh/test-hreflang-about"', $html);
        $custom = $twig->render('alternates.html.twig', ['page' => $page]);
        self::assertSame(4, \substr_count($custom, 'rel="alternate"'));
        unset($page['seo_alternates'], $page['seo_canonical']);
        $legacy = $twig->render('@ItkCms/layout.html.twig', ['page' => $page]);
        self::assertSame(\file_get_contents(__DIR__ . '/../Fixtures/seo/legacy-layout.html'), $legacy);
        self::assertStringNotContainsString('rel="alternate"', $legacy);
        self::assertStringNotContainsString('rel="canonical"', $legacy);
        self::assertStringContainsString('<html lang="ru">', $legacy);
    }
}

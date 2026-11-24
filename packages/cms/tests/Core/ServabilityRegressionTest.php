<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Content\PageVersions;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Repository\SeoRuleRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class ServabilityRegressionTest extends TestCase
{
    #[Test]
    public function publicVersionSelectorsRejectHistoricalPrefixMismatches(): void
    {
        $locales = new ContentLocales('ru', ['ru' => ['name' => 'Russian'], 'kk' => ['name' => 'Kazakh'], 'en' => ['name' => 'English']]);
        $rules = $this->createStub(SeoRuleRepository::class);
        $rules->method('findActive')->willReturn([]);
        $indexable = new IndexablePages($this->createStub(PageTranslationRepository::class), new SeoResolver($rules, new RequestStack(), 'ru'), 'ru', $locales);
        $versions = new PageVersions($locales, $indexable);
        foreach ([['en', '/about'], ['en', '/en-about'], ['ru', '/kk/about']] as [$locale, $path]) {
            $page = new Page();
            $t = new PageTranslation($page, $locale);
            $t->published = ['url' => $path];
            $t->publishedUrl = $path;
            $t->status = PageTranslation::PUBLISHED;
            $page->translations->set($locale, $t);
            self::assertFalse($indexable->admits($t), $locale . ': ' . $path);
            self::assertSame([], $versions->live($page));
        }
    }
}

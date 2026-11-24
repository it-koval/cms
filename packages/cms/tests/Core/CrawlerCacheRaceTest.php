<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Content\PageVersions;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Setting;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Repository\SeoRuleRepository;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\Core\Fixture\AfterBuildCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;

#[Group('no-database')]
final class CrawlerCacheRaceTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        try {
            if (self::$booted) {
                self::assertFalse(self::getContainer()->get(Connection::class)->isConnected());
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function generators(): iterable
    {
        yield 'sitemap mono' => [false, 'sitemap'];
        yield 'sitemap multilingual' => [true, 'sitemap'];
        yield 'llms mono' => [false, 'llms'];
        yield 'llms multilingual' => [true, 'llms'];
        yield 'sitemap mono generation evicted' => [false, 'sitemap', true];
        yield 'sitemap multilingual generation evicted' => [true, 'sitemap', true];
        yield 'llms mono generation evicted' => [false, 'llms', true];
        yield 'llms multilingual generation evicted' => [true, 'llms', true];
        yield 'sitemap contract-only cache' => [true, 'sitemap', false, true];
        yield 'llms contract-only cache' => [true, 'llms', false, true];
    }

    #[Test]
    #[DataProvider('generators')]
    public function aLateOldBuildCannotReplaceTheFileCreatedAfterInvalidation(bool $multi, string $kind, bool $evict = false, bool $contractsOnly = false): void
    {
        $c = self::getContainer();
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('findAll')->willReturn([new Setting('domain', '*', 'race.example.test'), new Setting('siteName', '*', 'Race')]);
        $settings = new SettingsService($this->createStub(EntityManagerInterface::class), $repo, $c->get(Schema::class), $c->get(FieldValues::class), $c->get(HistoryService::class), $c->get(FieldAccess::class), 'ru');
        $locales = new ContentLocales('ru', $multi ? ['ru' => ['name' => 'Russian'], 'zh' => ['name' => 'Chinese', 'html_lang' => 'zh-CN']] : null);
        $page = new Page();
        $t = new PageTranslation($page, 'ru');
        $path = '/test-crawler-race-' . \bin2hex(\random_bytes(6));
        $t->published = ['url' => $path, 'title' => 'Old public page'];
        $t->status = PageTranslation::PUBLISHED;
        $t->publishedAt = new \DateTimeImmutable('2026-01-01 UTC');
        $t->publishedUrl = $path;
        $pages = $this->createStub(PageTranslationRepository::class);
        $pages->method('findAllLive')->willReturn([$t]);
        $rules = $this->createStub(SeoRuleRepository::class);
        $rules->method('findActive')->willReturn([]);
        $index = new IndexablePages($pages, new SeoResolver($rules, new RequestStack(), 'ru'), 'ru', $locales);
        $cache = new AfterBuildCache();
        $adapter = !$contractsOnly ? $cache : new class($cache) implements CacheInterface {
            public function __construct(private readonly AfterBuildCache $cache)
            {
            }

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return $this->cache->get($key, $callback, $beta, $metadata);
            }

            public function delete(string $key): bool
            {
                return $this->cache->delete($key);
            }
        };
        $sitemap = new SitemapBuilder($index, $settings, $adapter, new PageVersions($locales, $index), new PublicUrl(new RequestStack(), $settings, 'https://race.example.test'));
        $files = new CrawlerFiles($settings, $sitemap, $index, $c->get(AdminPath::class), $adapter, __DIR__ . '/../App', $locales);
        $read = 'sitemap' === $kind ? $sitemap->xml(...) : static fn (): string => $files->defaultText(CrawlerFiles::LLMS);
        $fresh = null;
        $cache->afterBuild = static function () use ($t, $sitemap, $files, $read, $cache, $evict, $kind, &$fresh): void {
            // The newer committed state is noindex. Invalidation runs before the newer request, while the old callback has not saved yet.
            $t->published['seo'] = ['robots' => 'noindex,follow'];
            $sitemap->forget();
            $files->forget();
            if ($evict) {
                $cache->deleteItem('itk_crawler_generation_' . $kind);
            }
            $fresh = $read();
        };
        self::assertStringContainsString($path, $read(), 'the old in-flight response saw the original snapshot');
        self::assertIsString($fresh);
        self::assertStringNotContainsString($path, $fresh, 'the newer request saw the committed noindex snapshot');
        self::assertSame($fresh, $read(), 'the late old write must be unreachable to every subsequent request');
    }
}

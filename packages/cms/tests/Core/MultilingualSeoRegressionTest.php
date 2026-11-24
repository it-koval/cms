<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageValidators;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

final class MultilingualSeoRegressionTest extends WebTestCase
{
    use TemporarySettings;

    private KernelBrowser $client;
    private string $id;
    private string $path;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false, itk: [
            'public_url' => '%env(ITK_REGRESSION_SEO_BASE)%',
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Russian'], 'zh' => ['name' => 'Chinese', 'html_lang' => 'zh-CN']],
        ]);
    }

    protected function setUp(): void
    {
        $_SERVER['ITK_REGRESSION_SEO_BASE'] = $_ENV['ITK_REGRESSION_SEO_BASE'] = 'http://localhost';
        $this->client = static::createClient();
        $this->client->catchExceptions(false);
        $this->client->disableReboot();
        $this->path = '/test-seo-regression-' . \bin2hex(\random_bytes(6));
        $page = new Page('stock_locale');
        $this->id = $page->id;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($page);
        foreach (['ru' => $this->path, 'zh' => '/zh' . $this->path] as $locale => $path) {
            $t = new PageTranslation($page, $locale);
            $t->draft = $t->published = ['url' => $path, 'title' => 'SEO regression', 'seo' => [], 'sections' => [], 'fields' => []];
            $t->publishedUrl = $path;
            $t->publishedAt = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            $t->status = PageTranslation::PUBLISHED;
            $page->translations->set($locale, $t);
            $em->persist($t);
        }
        $em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->id)) {
                self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM itk_page WHERE id = ?', [$this->id]);
                self::getContainer()->get(SitemapBuilder::class)->forget();
                self::getContainer()->get(CrawlerFiles::class)->forget();
                self::getContainer()->get(PageCache::class)->clear();
            }
        } finally {
            try {
                $this->restoreSettings();
            } finally {
                unset($_SERVER['ITK_REGRESSION_SEO_BASE'], $_ENV['ITK_REGRESSION_SEO_BASE']);
                parent::tearDown();
            }
        }
    }

    private function base(string $base): void
    {
        self::ensureKernelShutdown();
        $_SERVER['ITK_REGRESSION_SEO_BASE'] = $_ENV['ITK_REGRESSION_SEO_BASE'] = $base;
        $this->client = static::createClient();
        $this->client->catchExceptions(false);
        $this->client->disableReboot();
    }

    #[Test]
    public function changingTheResolvedEnvironmentBaseWithoutRebuildingReplacesCachedHtmlAndValidators(): void
    {
        $this->setting('domain', 'localhost');
        $this->client->request('GET', $this->path);
        $before = $this->client->getResponse();
        self::assertSame(200, $before->getStatusCode());
        $oldTag = $before->getEtag();
        self::assertNotNull($oldTag);
        $build = self::getContainer()->get(PageValidators::class)->build();
        $this->client->request('GET', $this->path);
        self::assertSame('HIT', $this->client->getResponse()->headers->get('X-ITK-Cache'));

        $this->base('https://new-regression.example');
        self::assertSame($build, self::getContainer()->get(PageValidators::class)->build());
        $this->client->request('GET', $this->path, server: ['HTTP_IF_NONE_MATCH' => $oldTag]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($oldTag, $response->getEtag());
        self::assertStringContainsString('href="https://new-regression.example' . $this->path . '"', (string) $response->getContent());
    }

    #[Test]
    public function theCompleteOrderedLocaleMetadataParticipatesInTheEtag(): void
    {
        $c = self::getContainer();
        $t = $c->get(EntityManagerInterface::class)->find(Page::class, $this->id)->translation('ru');
        $etag = function (array $catalog) use ($c, $t): ?string {
            $validators = new PageValidators($c->get(Connection::class), true, false, 'same-build', 'SameContainer', $c->get(ClockInterface::class), $c->get(Stylesheets::class), new ContentLocales('ru', $catalog), $c->get(PublicUrl::class));

            return $validators->forPage($t, Request::create($this->path))['etag'] ?? null;
        };
        $catalog = ['ru' => ['name' => 'Russian'], 'zh' => ['name' => 'Chinese', 'html_lang' => 'zh-CN']];
        $before = $etag($catalog);
        self::assertNotNull($before);
        foreach ([['name' => 'Mandarin', 'html_lang' => 'zh-CN'], ['name' => 'Chinese', 'html_lang' => 'zh-TW'], ['name' => 'Chinese', 'html_lang' => 'zh-CN', 'og_locale' => 'zh_TW']] as $metadata) {
            self::assertNotSame($before, $etag(['ru' => $catalog['ru'], 'zh' => $metadata]));
        }
        self::assertNotSame($etag($catalog + ['en' => ['name' => 'English']]), $etag(['ru' => $catalog['ru'], 'en' => ['name' => 'English'], 'zh' => $catalog['zh']]));
    }

    #[Test]
    public function invalidRuntimePublicUrlOmitsAbsoluteSeoWithoutCrashingPublicRoutes(): void
    {
        $this->base('not-an-origin');
        $this->client->request('GET', $this->path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('rel="canonical"', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('hreflang=', (string) $this->client->getResponse()->getContent());
        $this->client->catchExceptions(true);
        $this->client->request('GET', '/sitemap.xml');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/robots.txt');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Sitemap:', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/llms.txt');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('not-an-origin', (string) $this->client->getResponse()->getContent());
        $this->expectException(\UnexpectedValueException::class);
        self::getContainer()->get(PublicUrl::class)->base();
    }

    #[Test]
    public function theStockLayoutPrintsARegionalOgLocaleAndOmitsAnUnknownRegion(): void
    {
        $this->client->request('GET', '/zh' . $this->path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('<html lang="zh-CN">', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('<meta property="og:locale" content="zh_CN">', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', $this->path);
        self::assertStringNotContainsString('property="og:locale"', (string) $this->client->getResponse()->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\PageVersions;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Controller\Site\CrawlerFilesController;
use Itk\Cms\Controller\Site\SitemapController;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/** Database integration tests, intentionally run only against the prepared isolated test database. */
final class SitemapLocalesTest extends WebTestCase
{
    private KernelBrowser $client;
    private PageService $pages;
    private SettingsService $settings;
    private SeoRuleService $rules;
    /** @var list<string> */
    private array $ids = [];
    /** @var array<string, array{bool, mixed}> */
    private array $before = [];
    private string $tmp = '';
    /** @var list<string> */
    private array $redirects = [];
    /** @var list<string> */
    private array $ruleIds = [];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: ['main_locale' => 'ru', 'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'], 'en' => ['name' => 'English']]]); // i18n-ignore: configured language names
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $c = self::getContainer();
        $this->pages = $c->get(PageService::class);
        $this->settings = $c->get(SettingsService::class);
        $this->rules = $c->get(SeoRuleService::class);
        foreach (['domain', 'siteName', 'llmsTxt'] as $key) {
            $this->before[$key] = [$this->settings->isSet($key), $this->settings->get($key, null, '')];
        }
        foreach (['domain' => 'test-locales.example.com', 'siteName' => 'Locale site', 'llmsTxt' => ''] as $key => $value) {
            $this->settings->set($key, $value);
        }
        $c->get(SitemapBuilder::class)->forget();
        $c->get(CrawlerFiles::class)->forget();
        $this->tmp = __DIR__ . '/../App/var/seo-locales-' . \bin2hex(\random_bytes(5));
        (new Filesystem())->mkdir($this->tmp . '/public');
    }

    protected function tearDown(): void
    {
        try {
            $c = self::getContainer();
            foreach ($this->ruleIds as $id) {
                $this->rules->remove($id);
            }
            foreach (\array_reverse($this->ids) as $id) {
                $this->pages->remove($id);
            }
            $em = $c->get(EntityManagerInterface::class);
            if ([] !== $this->redirects) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Redirect r WHERE r.fromPath IN (:paths)')->setParameter('paths', $this->redirects)->execute();
            }
            if ([] !== $this->ids) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.kind = :kind AND r.resourceId IN (:ids)')->setParameter('kind', Revision::PAGE)->setParameter('ids', $this->ids)->execute();
            }
            foreach ($this->before as $key => [$set, $value]) {
                if ($set) {
                    $this->settings->set($key, $value);
                } else {
                    $this->settings->remove($key);
                }
            }
            $this->settings->reset();
            $c->get(SitemapBuilder::class)->forget();
            $c->get(CrawlerFiles::class)->forget();
        } finally {
            try {
                if ('' !== $this->tmp) {
                    (new Filesystem())->remove($this->tmp);
                }
            } finally {
                parent::tearDown();
            }
        }
    }

    /** @return array<array-key, mixed> */
    private function fixture(): array
    {
        $url = '/test-sitemap-locales-' . \bin2hex(\random_bytes(6));
        $page = $this->pages->create(['template' => 'default', 'url' => $url, 'title' => 'Русская страница', 'seo' => ['description' => 'RU description']]); // i18n-ignore: fixture content
        $this->ids[] = $page['id'];
        $this->pages->publish($page['id'], 'ru');
        foreach (['kk', 'tr', 'zh'] as $locale) {
            $this->pages->addTranslation($page['id'], $locale, 'ru');
            $this->pages->update($page['id'], $locale, ['title' => $locale . ' title', 'seo' => ['description' => $locale . ' description']]);
        }
        $this->pages->publish($page['id'], 'zh');

        return $page;
    }

    private function get(string $path, ?string $etag = null, ?string $modified = null): Response
    {
        $server = [];
        if (null !== $etag) {
            $server['HTTP_IF_NONE_MATCH'] = $etag;
        }
        if (null !== $modified) {
            $server['HTTP_IF_MODIFIED_SINCE'] = $modified;
        }
        $this->client->request('GET', $path, server: $server);

        return $this->client->getResponse();
    }

    /** @return list<array{hreflang: string, href: string}> */
    private function htmlAlternates(string $path): array
    {
        $response = $this->get($path);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        \preg_match_all('~<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">~', $html, $matches, \PREG_SET_ORDER);

        return \array_map(static fn (array $m): array => ['hreflang' => $m[1], 'href' => $m[2]], $matches);
    }

    #[Test]
    public function warmedGeneratorsAndValidatorsFollowPublishMoveAndUnpublishInTheSameSecond(): void
    {
        $page = $this->fixture();
        $id = $page['id'];
        $path = $page['url'];
        $xml = $this->get('/sitemap.xml');
        $text = $this->get('/llms.txt');
        self::assertStringNotContainsString('https://test-locales.example.com/kk' . $path, (string) $xml->getContent());
        self::assertStringNotContainsString('https://test-locales.example.com/kk' . $path, (string) $text->getContent());
        foreach (['/sitemap.xml' => $xml, '/llms.txt' => $text] as $address => $response) {
            self::assertNotNull($response->getEtag());
            self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
            self::assertSame(304, $this->get($address, $response->getEtag())->getStatusCode());
            self::assertSame(200, $this->get($address, modified: \gmdate('D, d M Y H:i:s') . ' GMT')->getStatusCode());
        }
        $html = $this->get($path);
        $this->pages->publish($id, 'kk');
        foreach (['/kk' . $path, '/kk' . $path . '-moved', null] as $current) {
            if (null === $current) {
                $this->pages->unpublish($id, 'kk');
            } elseif (\str_ends_with($current, '-moved')) {
                $this->redirects[] = '/kk' . $path;
                $this->pages->update($id, 'kk', ['url' => $current]);
                $this->pages->publish($id, 'kk');
            }
            $freshXml = $this->get('/sitemap.xml', $xml->getEtag(), $xml->headers->get('Last-Modified'));
            $freshText = $this->get('/llms.txt', $text->getEtag(), $text->headers->get('Last-Modified'));
            $freshHtml = $this->get($path, $html->getEtag(), $html->headers->get('Last-Modified'));
            self::assertSame(200, $freshXml->getStatusCode());
            self::assertSame(200, $freshText->getStatusCode());
            self::assertSame(200, $freshHtml->getStatusCode());
            $alternates = self::getContainer()->get(PageVersions::class)->alternates($this->pages->find($id), 'https://test-locales.example.com');
            self::assertSame($alternates, $this->htmlAlternates($path));
            self::assertSame($alternates, $this->htmlAlternates('/zh' . $path));
            if (null !== $current) {
                self::assertSame($alternates, $this->htmlAlternates($current));
            }
            $doc = new \DOMDocument();
            self::assertTrue($doc->loadXML((string) $freshXml->getContent()));
            $xp = new \DOMXPath($doc);
            $xp->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $xp->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
            $xmlLinks = [];
            foreach ($xp->query('//sm:url[sm:loc="https://test-locales.example.com' . $path . '"]/xhtml:link') as $link) {
                self::assertInstanceOf(\DOMElement::class, $link);
                $xmlLinks[] = ['hreflang' => $link->getAttribute('hreflang'), 'href' => $link->getAttribute('href')];
            }
            self::assertSame($alternates, $xmlLinks, 'XML preserves the exact HTML set and order');
            if ($current !== '/kk' . $path) {
                self::assertSame(0, $xp->query('//sm:url[sm:loc="https://test-locales.example.com/kk' . $path . '"]')->length);
                self::assertStringNotContainsString('(https://test-locales.example.com/kk' . $path . ')', (string) $freshText->getContent());
            }
            if (null !== $current) {
                self::assertStringContainsString('https://test-locales.example.com' . $current, (string) $freshXml->getContent());
                self::assertStringContainsString('https://test-locales.example.com' . $current, (string) $freshText->getContent());
                $body = (string) $freshText->getContent();
                self::assertStringContainsString('## Қазақша', $body);
                self::assertLessThan(\strpos($body, '## Қазақша'), \strpos($body, '## Русский'));
                self::assertLessThan(\strpos($body, '## 中文'), \strpos($body, '## Қазақша'));
                self::assertStringNotContainsString('## Türkçe', $body);
            } else {
                self::assertStringNotContainsString('https://test-locales.example.com/kk' . $path, (string) $freshXml->getContent());
                self::assertStringNotContainsString('https://test-locales.example.com/kk' . $path, (string) $freshText->getContent());
            }
            self::assertStringNotContainsString('https://test-locales.example.com/tr' . $path, (string) $freshXml->getContent());
            $xml = $freshXml;
            $text = $freshText;
            $html = $freshHtml;
        }
    }

    #[Test]
    public function noindexIsAbsentEverywhereAndManualAndStaticFilesKeepPrecedence(): void
    {
        $page = $this->fixture();
        $this->pages->publish($page['id'], 'kk');
        $path = '/kk' . $page['url'];
        $url = 'https://test-locales.example.com' . $path;
        $xml = $this->get('/sitemap.xml');
        $text = $this->get('/llms.txt');
        self::assertStringContainsString($url, (string) $xml->getContent());
        self::assertStringContainsString($url, (string) $text->getContent());
        self::assertContains(['hreflang' => 'kk', 'href' => $url], $this->htmlAlternates($page['url']));
        self::assertNotSame([], $this->htmlAlternates($path));

        $rule = $this->rules->create(['path' => $path, 'locale' => 'kk', 'robots' => 'noindex,follow']);
        $this->ruleIds[] = $rule->id;
        $freshXml = $this->get('/sitemap.xml', $xml->getEtag());
        $freshText = $this->get('/llms.txt', $text->getEtag());
        self::assertSame(200, $freshXml->getStatusCode());
        self::assertSame(200, $freshText->getStatusCode());
        self::assertStringNotContainsString($url, (string) $freshXml->getContent());
        self::assertStringNotContainsString($url, (string) $freshText->getContent());
        self::assertSame([], $this->htmlAlternates($path));
        self::assertStringContainsString('<meta name="robots" content="noindex,follow"', (string) $this->client->getResponse()->getContent());
        $alternates = self::getContainer()->get(PageVersions::class)->alternates($this->pages->find($page['id']), 'https://test-locales.example.com');
        self::assertNotSame([], $alternates);
        self::assertNotContains(['hreflang' => 'kk', 'href' => $url], $alternates);
        foreach ([$page['url'], '/zh' . $page['url']] as $alternatePath) {
            self::assertSame($alternates, $this->htmlAlternates($alternatePath));
        }
        $this->settings->set('llmsTxt', 'Manual text');
        self::assertSame("Manual text\n", $this->get('/llms.txt')->getContent());
        \file_put_contents($this->tmp . '/public/sitemap.xml', '<urlset/>');
        \file_put_contents($this->tmp . '/public/llms.txt', "Static text\n");
        $c = self::getContainer();
        $staticXml = (new SitemapController($c->get(SitemapBuilder::class), $this->tmp))();
        self::assertInstanceOf(BinaryFileResponse::class, $staticXml);
        self::assertSame($this->tmp . '/public/sitemap.xml', $staticXml->getFile()->getPathname());
        $files = new CrawlerFiles($this->settings, $c->get(SitemapBuilder::class), $c->get(IndexablePages::class), $c->get(AdminPath::class), new ArrayAdapter(), $this->tmp, $c->get(ContentLocales::class));
        $staticText = (new CrawlerFilesController($files))->llms();
        self::assertInstanceOf(BinaryFileResponse::class, $staticText);
        self::assertSame($this->tmp . '/public/llms.txt', $staticText->getFile()->getPathname());
    }

    #[Test]
    public function singleLanguageGeneratedXmlAndTextKeepTheirExactPreviousFormat(): void
    {
        $page = new Page();
        $t = new PageTranslation($page, 'ru');
        $t->published = ['url' => '/about', 'title' => 'About', 'seo' => ['description' => 'Description']];
        $t->publishedUrl = '/about';
        $t->publishedAt = new \DateTimeImmutable('2026-01-02 03:04:05 UTC');
        $t->status = PageTranslation::PUBLISHED;
        $repo = $this->createStub(PageTranslationRepository::class);
        $repo->method('findAllLive')->willReturn([$t]);
        $c = self::getContainer();
        $locales = new ContentLocales('ru');
        $indexable = new IndexablePages($repo, $c->get(SeoResolver::class), 'ru', $locales);
        $sitemap = new SitemapBuilder($indexable, $this->settings, new ArrayAdapter(), new PageVersions($locales, $indexable));
        self::assertSame("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n  <url><loc>https://test-locales.example.com/about</loc><lastmod>2026-01-02T03:04:05Z</lastmod></url>\n</urlset>\n", $sitemap->xml());
        $files = new CrawlerFiles($this->settings, $sitemap, $indexable, $c->get(AdminPath::class), new ArrayAdapter(), $this->tmp, $locales);
        self::assertSame("# Locale site\n\n## Pages\n\n- [About](https://test-locales.example.com/about): Description", $files->defaultText(CrawlerFiles::LLMS));
    }
}

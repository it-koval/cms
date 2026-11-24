<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Content\SeoAddressCheck;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\IndexNow\IndexNowState;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\SchemaOrg\GraphBuilder;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;

/** Real SEO components must use the site configuration, regardless of the request host, scheme or port. */
final class SeoOriginConsistencyTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $itk = ['public_url' => '%env(ITK_TEST_SEO_ORIGIN)%', 'page_cache' => ['enabled' => false]];
        if ($options['multilingual']) {
            $itk['locales'] = ['ru' => ['name' => 'Russian'], 'en' => ['name' => 'English']];
        }

        return new TestKernel('test', true, itk: $itk);
    }

    /** @return iterable<string, array{bool, string, string, ?string}> */
    public static function origins(): iterable
    {
        foreach ([false, true] as $multilingual) {
            foreach ([
                'public_url wins' => ['HTTPS://Canonical.Example.org:443/', 'legacy.example.org', 'https://canonical.example.org'],
                'domain IDN' => ['', 'Пример.рф', 'https://xn--e1afmkfd.xn--p1ai'],
                'domain nondefault port' => ['', 'http://Legacy.Example.org:8080/', 'http://legacy.example.org:8080'],
                'invalid optional public_url' => ['https://invalid.example/path', 'Example.org:443', 'https://example.org'],
                'no address' => ['', '', null],
                'invalid optional origin and no domain' => ['https://invalid.example/path', '', null],
            ] as $name => [$configured, $domain, $expected]) {
                yield ($multilingual ? 'multiple ' : 'single ') . $name => [$multilingual, $configured, $domain, $expected];
            }
        }
    }

    #[Test]
    #[DataProvider('origins')]
    public function allSeoOutputsUseTheSameConfiguredOrigin(bool $multilingual, string $configured, string $domain, ?string $expected): void
    {
        $_SERVER['ITK_TEST_SEO_ORIGIN'] = $_ENV['ITK_TEST_SEO_ORIGIN'] = $configured;
        self::bootKernel(['multilingual' => $multilingual]);
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->beginTransaction();
        $requests = $c->get(RequestStack::class);
        $trusted = Request::getTrustedHosts();
        $sitemap = $c->get(SitemapBuilder::class);
        $files = $c->get(CrawlerFiles::class);
        try {
            $settings = $c->get(SettingsService::class);
            foreach (['domain' => $domain, 'siteName' => 'Origin fixture', 'schemaOrgOff' => false, 'indexNow' => true] as $key => $value) {
                $settings->set($key, $value);
            }
            $page = new Page('default');
            $versions = [];
            $path = '/test-seo-origin-' . \bin2hex(\random_bytes(5));
            foreach ($multilingual ? ['ru', 'en'] : ['ru'] as $locale) {
                $t = new PageTranslation($page, $locale);
                $t->publishedUrl = ('ru' === $locale ? '' : '/en') . $path;
                $t->published = $t->draft = ['title' => 'Origin fixture', 'url' => $t->publishedUrl, 'seo' => [], 'sections' => [], 'fields' => []];
                $t->status = PageTranslation::PUBLISHED;
                $t->publishedAt = new \DateTimeImmutable();
                $page->translations->set($locale, $t);
                $em->persist($t);
                $versions[] = $t;
            }
            $em->persist($page);
            $em->flush();
            $sitemap->forget();
            $files->forget();
            // Both are allowed by trusted_hosts; neither is the canonical site origin.
            Request::setTrustedHosts(['^request\\.example\\.org$', '^alias\\.example\\.org$']);
            foreach (['http://request.example.org:8090', 'https://alias.example.org:9443'] as $requestOrigin) {
                $requests->push(Request::create($requestOrigin . $path));
                try {
                    $xml = $sitemap->xml();
                    $html = (string) $c->get(PageRenderer::class)->renderLive($versions[0])->getContent();
                    $check = $c->get(SeoAddressCheck::class)->check($path);
                    $robots = $files->defaultText(CrawlerFiles::ROBOTS);
                    $llms = $files->defaultText(CrawlerFiles::LLMS);
                    $graph = $c->get(GraphBuilder::class)->build($versions[0]);
                    $indexNow = $c->get(IndexNowSite::class);
                    self::assertSame($expected, $c->get(PublicUrl::class)->seoBase());
                    self::assertSame($expected, $sitemap->base());
                    self::assertSame($expected, $sitemap->address()?->origin());
                    self::assertStringNotContainsString($requestOrigin, (string) $xml . $html . $robots . $llms . \json_encode($graph));
                    if (null === $expected) {
                        self::assertNull($xml);
                        self::assertStringNotContainsString('rel="canonical"', $html);
                        self::assertStringNotContainsString('hreflang=', $html);
                        self::assertSame('', $check['meta']['canonical']['value']);
                        self::assertStringNotContainsString('Sitemap:', $robots);
                        self::assertStringContainsString('](' . $path . ')', $llms);
                        self::assertSame([], $graph);
                        self::assertSame(IndexNowState::NoAddress, $indexNow->state());
                    } else {
                        self::assertStringContainsString('<loc>' . $expected . $path . '</loc>', (string) $xml);
                        self::assertStringContainsString('rel="canonical" href="' . $expected . $path . '"', $html);
                        self::assertSame($expected . $path, $check['meta']['canonical']['value']);
                        self::assertStringContainsString('Sitemap: ' . $expected . '/sitemap.xml', $robots);
                        self::assertStringContainsString('](' . $expected . $path . ')', $llms);
                        self::assertSame($expected . '/', $graph[0]['url']);
                        self::assertSame($expected, $indexNow->address()->origin());
                        self::assertStringStartsWith($expected . '/', $indexNow->keyLocation());
                        if ($multilingual) {
                            self::assertStringContainsString('hreflang="en" href="' . $expected . '/en' . $path . '"', (string) $xml);
                            self::assertStringContainsString('hreflang="en" href="' . $expected . '/en' . $path . '"', $html);
                        }
                    }
                    $relative = $c->get(SeoResolver::class)->meta(['canonical' => '/preferred']);
                    self::assertSame(($expected ?? '') . '/preferred', $relative['canonical']);
                } finally {
                    $requests->pop();
                }
            }
        } finally {
            Request::setTrustedHosts($trusted);
            $sitemap->forget();
            $files->forget();
            $db->rollBack();
            $em->clear();
            unset($_SERVER['ITK_TEST_SEO_ORIGIN'], $_ENV['ITK_TEST_SEO_ORIGIN']);
        }
    }
}

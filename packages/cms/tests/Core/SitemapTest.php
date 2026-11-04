<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Controller\Site\SitemapController;
use Itk\Cms\Entity\User;
use Itk\Cms\Support\Url;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** GET /sitemap.xml: опубликованные страницы, абсолютные адреса из настройки «Адрес сайта», без noindex, кеш сбрасывается при публикации. */
final class SitemapTest extends WebTestCase
{
    private KernelBrowser $client;
    private PageService $pages;
    private SettingsService $settings;
    private SitemapBuilder $sitemap;
    private mixed $domainBefore;
    private bool $domainWasSet;
    /** @var list<string> */
    private array $ids = [];
    private ?string $tmp = null;
    /** @var list<string> domains the test set: the kept sitemap of each is dropped at the end */
    private array $domains = ['test-sitemap.example.com'];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('test-sitemap@example.com', 'Карта', 'admin'), 'itk_admin', []));
        $this->pages = $c->get(PageService::class);
        $this->settings = $c->get(SettingsService::class);
        $this->sitemap = $c->get(SitemapBuilder::class);
        $this->domainWasSet = $this->settings->isSet('domain');
        $this->domainBefore = $this->settings->get('domain', null, '');
        $this->settings->set('domain', 'test-sitemap.example.com');
        $this->sitemap->forget();
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        foreach ($this->domains as $d) {
            $this->settings->set('domain', $d);
            $this->sitemap->forget();
        }
        $em = $c->get(EntityManagerInterface::class);
        $em->createQuery("DELETE FROM Itk\\Cms\\Entity\\SeoRule r WHERE r.path LIKE '/test-%'")->execute();
        foreach (\array_reverse($this->ids) as $id) {
            try {
                $this->pages->remove($id);
            } catch (\Throwable) {
            }
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', [] === $this->ids ? ['-'] : $this->ids)->execute();
        if ($this->domainWasSet) {
            $this->settings->set('domain', $this->domainBefore);
        } else {
            $em->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'domain'")->execute();
        }
        $this->sitemap->forget();
        if (null !== $this->tmp) {
            @\unlink($this->tmp . '/public/sitemap.xml');
            @\rmdir($this->tmp . '/public');
            @\rmdir($this->tmp);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function live(string $title, string $url, array $extra = []): array
    {
        $page = $this->pages->create(['template' => 'default', 'title' => $title, 'url' => $url] + $extra);
        $this->ids[] = $page['id'];
        $this->pages->publish($page['id']);

        return $page;
    }

    private function useDomain(string $domain): void
    {
        $this->settings->set('domain', $domain);
        $this->domains[] = $domain;
        $this->sitemap->forget();
    }

    private function xml(): string
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        \preg_match_all('~<loc>([^<]*)</loc>~', $xml, $m);

        return $m[1];
    }

    #[Test]
    public function livePagesAreListedWithAbsoluteAddressesAndTheDayTheyWerePublished(): void
    {
        $this->live('Карта: живая', '/test-sm-live');
        $this->ids[] = $this->pages->create(['template' => 'default', 'title' => 'Карта: черновик', 'url' => '/test-sm-draft'])['id'];

        $xml = $this->xml();

        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        self::assertContains('https://test-sitemap.example.com/test-sm-live', $this->locs($xml));
        self::assertNotContains('https://test-sitemap.example.com/test-sm-draft', $this->locs($xml), 'a draft is not on the site');
        self::assertMatchesRegularExpression('~<loc>https://test-sitemap\.example\.com/test-sm-live</loc><lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ</lastmod>~', $xml);
        self::assertTrue((new \DOMDocument())->loadXML($xml), 'it is well-formed XML');
        foreach ($this->locs($xml) as $loc) {
            self::assertStringStartsWith('https://test-sitemap.example.com/', $loc);
        }
    }

    #[Test]
    public function theAddressComesFromTheSettingAndNotFromTheHostOfTheRequest(): void
    {
        $this->live('Карта: хост', '/test-sm-host');

        $this->client->request('GET', '/sitemap.xml', server: ['HTTP_HOST' => 'evil.example']);
        $xml = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('evil.example', $xml);
        self::assertContains('https://test-sitemap.example.com/test-sm-host', $this->locs($xml));
    }

    #[Test]
    public function aSchemeInTheSettingIsKeptAndAJunkValueGivesNoSitemap(): void
    {
        $this->live('Карта: схема', '/test-sm-scheme');

        $this->useDomain('http://test-sitemap.example.com/');
        self::assertContains('http://test-sitemap.example.com/test-sm-scheme', $this->locs($this->xml()));

        foreach (['', 'test sitemap"><x', 'javascript:alert(1)'] as $junk) {
            $this->useDomain($junk);
            $this->client->request('GET', '/sitemap.xml');
            self::assertResponseStatusCodeSame(404, 'domain: ' . $junk);
        }
    }

    #[Test]
    public function pagesThatAskNotToBeIndexedAreLeftOut(): void
    {
        $this->live('Карта: видна', '/test-sm-visible');
        $this->live('Карта: скрыта своим SEO', '/test-sm-own-noindex', ['seo' => ['robots' => 'noindex,follow']]);
        $this->live('Карта: скрыта правилом', '/test-sm-rule-noindex');
        self::getContainer()->get(SeoRuleService::class)->create(['path' => '/test-sm-rule-noindex', 'match' => 'exact', 'robots' => 'noindex,nofollow']);

        $locs = $this->locs($this->xml());

        self::assertContains('https://test-sitemap.example.com/test-sm-visible', $locs);
        self::assertNotContains('https://test-sitemap.example.com/test-sm-own-noindex', $locs);
        self::assertNotContains('https://test-sitemap.example.com/test-sm-rule-noindex', $locs);
    }

    #[Test]
    public function aPageThatIsPublishedOrTakenOffChangesTheSitemapAtOnce(): void
    {
        $a = $this->live('Карта: первая', '/test-sm-first');
        self::assertNotContains('https://test-sitemap.example.com/test-sm-second', $this->locs((string) $this->sitemap->xml()));
        self::assertContains('https://test-sitemap.example.com/test-sm-first', $this->locs((string) $this->sitemap->xml()), 'the answer is kept now');

        $this->live('Карта: вторая', '/test-sm-second');
        self::assertContains('https://test-sitemap.example.com/test-sm-second', $this->locs((string) $this->sitemap->xml()), 'published: the kept answer was dropped');

        $this->pages->unpublish($a['id']);
        self::assertNotContains('https://test-sitemap.example.com/test-sm-first', $this->locs((string) $this->sitemap->xml()), 'taken off: dropped again');
        self::assertContains('https://test-sitemap.example.com/test-sm-second', $this->locs((string) $this->sitemap->xml()));
    }

    #[Test]
    public function aRuleThatClosesAnAddressChangesTheSitemapAtOnce(): void
    {
        $this->live('Карта: правило', '/test-sm-later');
        self::assertContains('https://test-sitemap.example.com/test-sm-later', $this->locs((string) $this->sitemap->xml()));

        self::getContainer()->get(SeoRuleService::class)->create(['path' => '/test-sm-later', 'match' => 'exact', 'robots' => 'noindex,follow']);

        self::assertNotContains('https://test-sitemap.example.com/test-sm-later', $this->locs((string) $this->sitemap->xml()));
    }

    #[Test]
    public function onlyTheMainLanguageIsListedBecauseTheRouteServesOnlyIt(): void
    {
        $this->live('Карта: основной язык', '/test-sm-lang');
        $other = $this->pages->create(['template' => 'default', 'title' => 'Map: other language', 'url' => '/test-sm-lang-en', 'locale' => 'en']);
        $this->ids[] = $other['id'];
        $this->pages->publish($other['id'], 'en');
        $this->sitemap->forget();

        $locs = $this->locs($this->xml());

        self::assertContains('https://test-sitemap.example.com/test-sm-lang', $locs);
        self::assertNotContains('https://test-sitemap.example.com/test-sm-lang-en', $locs, 'the address of a non-main language is not served');
    }

    #[Test]
    public function cyrillicAndOddCharactersInAnAddressAreEncoded(): void
    {
        $encoded = Url::encodePath('/блог/a b"c');

        self::assertSame('/%D0%B1%D0%BB%D0%BE%D0%B3/a%20b%22c', $encoded);
    }

    #[Test]
    public function aStaticSitemapOfTheSiteWinsOverTheGeneratedOne(): void
    {
        $this->tmp = \sys_get_temp_dir() . '/test-sitemap-' . \bin2hex(\random_bytes(4));
        \mkdir($this->tmp . '/public', 0777, true);
        \file_put_contents($this->tmp . '/public/sitemap.xml', '<urlset><url><loc>https://static.example/</loc></url></urlset>');

        $response = (new SitemapController($this->sitemap, $this->tmp))();

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame($this->tmp . '/public/sitemap.xml', $response->getFile()->getPathname());
    }

    #[Test]
    public function theRouteIsTheCoreOneAndNeverShadowsThePages(): void
    {
        $router = self::getContainer()->get('router');

        self::assertSame('itk_sitemap', $router->match('/sitemap.xml')['_route']);
        self::assertSame('itk_page', $router->match('/sitemap')['_route']);
    }
}

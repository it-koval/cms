<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoAddressCheck;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\SeoRule;
use Itk\Cms\Support\Url;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

final class PublicLocaleRouteTest extends WebTestCase
{
    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $rules = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        $itk = ['main_locale' => 'ru', 'page_cache' => ['enabled' => false]];
        if ($options['contentLocales'] ?? true) {
            $itk['locales'] = [
                'ru' => ['name' => 'Русский'],
                'kk' => ['name' => 'Қазақша'],
                'tr' => ['name' => 'Türkçe'],
                'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'],
                'en' => ['name' => 'English'],
            ]; // i18n-ignore: configured language names
            if ($options['disableKazakh'] ?? false) {
                unset($itk['locales']['kk']);
            }
        }

        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, framework: ['http_client' => ['enabled' => true]], itk: $itk);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->pages || [] !== $this->rules) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            if ([] !== $this->pages) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', $this->pages)->execute();
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id IN (:ids)')->setParameter('ids', $this->pages)->execute();
            }
            if ([] !== $this->rules) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\SeoRule r WHERE r.id IN (:ids)')->setParameter('ids', $this->rules)->execute();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function aLanguageHomeAnswersOnlyAfterPublication(): void
    {
        $client = static::createClient();
        $home = $this->version('kk', '/kk', 'Қазақша басты бет', live: false);

        $client->request('GET', '/kk');
        self::assertResponseStatusCodeSame(404);
        $this->assertSeoStatus($client, '/kk');

        self::getContainer()->get(PageService::class)->publish($home->page->id, 'kk');
        $client->request('GET', '/kk');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Қазақша басты бет', (string) $client->getResponse()->getContent());
        $this->assertSeoStatus($client, '/kk');
    }

    #[Test]
    public function theBundleLayoutUsesTheConfiguredHtmlLanguageTag(): void
    {
        $client = static::createClient();
        $this->version('zh', '/zh', '中文首页');
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([new ArrayLoader([
            'layout.html.twig' => "{% extends '@ItkCms/layout.html.twig' %}",
        ]), $twig->getLoader()]));

        $client->request('GET', '/zh');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('<html lang="zh-CN">', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function consecutiveRequestsOnTheSameKernelUseTheirOwnTemplateLanguage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->version('ru', '/test-locale-order', 'RU page');
        $this->version('kk', '/kk/test-locale-order', 'KK page');
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([new ArrayLoader([
            'pages/default.html.twig' => "{{ 'public_locale_route_probe'|trans }} {{ app.request.locale }}",
        ]), $twig->getLoader()]));

        foreach ([['/test-locale-order', 'Russian text ru'], ['/kk/test-locale-order', 'Kazakh text kk'], ['/test-locale-order', 'Russian text ru']] as [$path, $text]) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(200);
            self::assertStringContainsString($text, (string) $client->getResponse()->getContent());
        }
    }

    #[Test]
    public function exactLanguageSegmentsResolveWithTheExistingCanonicalRedirects(): void
    {
        $client = static::createClient();
        $this->version('kk', '/kk/about', 'Біз туралы');
        $this->version('ru', '/kk-news', 'Новости');
        $this->rule('/kk/about', 'kk', 'Қазақша SEO');
        $this->rule('/kk/about', 'ru', 'Русское SEO');

        $client->request('GET', '/kk/about');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Біз туралы', (string) $client->getResponse()->getContent());
        $this->assertSeoStatus($client, '/kk/about');
        $check = self::getContainer()->get(SeoAddressCheck::class);
        $result = $check->check('/kk/about');
        self::assertSame('Біз туралы', $result['answer']['page']['title']);
        self::assertSame('kk', $result['rules'][0]['locale']);
        $result = $check->check('/kk/about', 'ru');
        self::assertSame('Біз туралы', $result['answer']['page']['title']);
        self::assertSame('ru', $result['rules'][0]['locale']);

        $client->request('HEAD', '/kk/about');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('', $client->getResponse()->getContent());
        foreach (['/kk/about/?a=1', '/kk//about?a=1'] as $address) {
            $client->request('GET', $address);
            self::assertResponseRedirects('/kk/about?a=1', 301);
            $this->assertSeoStatus($client, $address);
        }

        $this->version('kk', '/kk//about', 'Сақталған мекенжай');
        $client->request('GET', '/kk//about');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Сақталған мекенжай', (string) $client->getResponse()->getContent());

        $client->request('GET', '/kk-news');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Новости', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function aStoredVersionOfADisabledLanguageIsNotPublic(): void
    {
        $client = static::createClient(['disableKazakh' => true]);
        $this->version('kk', '/kk/about', 'Біз туралы');

        $client->request('GET', '/kk/about');
        self::assertResponseStatusCodeSame(404);
        $this->assertSeoStatus($client, '/kk/about');
    }

    #[Test]
    public function anEncodedPublicAddressUsesTheSameSeoRuleAsItsStoredPath(): void
    {
        $client = static::createClient();
        $this->version('kk', '/kk/Қала', 'Қала');
        $this->rule('/kk/Қала', 'kk', 'Қала жайлы');

        $address = '/kk/' . \rawurlencode('Қала');
        $client->request('GET', $address);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('<title>Қала жайлы</title>', (string) $client->getResponse()->getContent());
        $this->assertSeoStatus($client, $address);
        self::assertSame(['value' => 'Қала жайлы', 'source' => 'rule'], self::getContainer()->get(SeoAddressCheck::class)->check($address)['meta']['title']);
    }

    #[Test]
    public function anUnconfiguredSiteServesItsExistingMainLanguageAddresses(): void
    {
        $client = static::createClient(['contentLocales' => false]);
        $this->version('ru', '/', 'Главная');
        $this->version('ru', '/about', 'О нас');
        $this->version('ru', '/kk/about', 'Прежний адрес');

        foreach (['/' => 'Главная', '/about' => 'О нас', '/kk/about' => 'Прежний адрес'] as $address => $title) {
            $client->request('GET', $address);
            self::assertResponseStatusCodeSame(200);
            self::assertStringContainsString($title, (string) $client->getResponse()->getContent());
        }
    }

    private function version(string $locale, string $path, string $title, bool $live = true): PageTranslation
    {
        $page = new Page();
        $this->pages[] = $page->id;
        $version = new PageTranslation($page, $locale);
        $page->translations->set($locale, $version);
        $version->draft = [
            'url' => $path,
            'title' => $title,
            'seo' => [],
            'fields' => [],
            'sections' => [['id' => 'hero', 'type' => 'hero', 'data' => ['headline' => $title]]],
        ];
        if ($live) {
            $version->published = $version->draft;
            $version->publishedAt = new \DateTimeImmutable();
            $version->publishedUrl = Url::key($path);
            $version->status = PageTranslation::PUBLISHED;
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($page);
        $em->flush();

        return $version;
    }

    private function rule(string $path, string $locale, string $title): void
    {
        $rule = new SeoRule($path);
        $this->rules[] = $rule->id;
        $rule->locale = $locale;
        $rule->title = $title;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($rule);
        $em->flush();
    }

    private function assertSeoStatus(KernelBrowser $client, string $address): void
    {
        $result = self::getContainer()->get(SeoAddressCheck::class)->check($address);
        self::assertSame($client->getResponse()->getStatusCode(), $result['answer']['status']);
    }
}

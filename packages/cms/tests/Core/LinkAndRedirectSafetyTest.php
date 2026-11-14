<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Schema\Field;
use Itk\Cms\Support\Url;
use Itk\Cms\Twig\ItkRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

/** Ссылки и перенаправления: поле «Ссылка» не пропускает javascript: и чужие адреса, Url::safeLocalPath, перенаправление «лишний слэш» остаётся на своём сайте. */
final class LinkAndRedirectSafetyTest extends WebTestCase
{
    private KernelBrowser $client;
    /** @var list<string> */
    private array $areas = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $menus = self::getContainer()->get(MenuService::class);
        foreach ($this->areas as $key) {
            try {
                $menus->removeArea($key);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function localPaths(): iterable
    {
        yield 'backslash host' => ['/\\evil.example/', '/evil.example/'];
        yield 'protocol-relative' => ['//evil.example/x', '/evil.example/x'];
        yield 'many slashes' => ['////evil.example', '/evil.example'];
        yield 'only backslashes' => ['\\\\evil.example', '/evil.example'];
        yield 'tab inside' => ["/\t/evil.example", '/evil.example'];
        yield 'line break inside' => ["/\n/evil.example", '/evil.example'];
        yield 'ordinary path' => ['/blog/post', '/blog/post'];
        yield 'query and fragment stay' => ['/a/b?x=1#h', '/a/b?x=1#h'];
        yield 'empty' => ['', '/'];
        yield 'no slash' => ['blog', '/blog'];
        yield 'encoded backslash is only text' => ['/%5Cevil.example', '/%5Cevil.example'];
    }

    #[Test]
    #[DataProvider('localPaths')]
    public function safeLocalPathAlwaysStaysOnTheSite(string $in, string $out): void
    {
        self::assertSame($out, Url::safeLocalPath($in));
        self::assertTrue(Url::isLocalPath(Url::safeLocalPath($in)));
    }

    #[Test]
    public function spellingKeepsTheStoredTrailingSlashAndStaysLocal(): void
    {
        self::assertSame('/blog/', Url::spelling('/blog/'));
        self::assertSame('/blog/', Url::spelling('blog/'));
        self::assertSame('/blog', Url::spelling('/blog'));
        self::assertSame('/', Url::spelling('/'));
        self::assertSame('/', Url::spelling('//'));
        self::assertSame('/', Url::spelling(null));
        self::assertSame('/evil/', Url::spelling('//evil/'));
        self::assertSame('/evil/', Url::spelling('/\\evil/'));
        self::assertSame('/evil', Url::spelling('//evil'));
        foreach (['//evil/', '/\\evil/', "/\t/evil/", '\\\\evil/'] as $in) {
            self::assertTrue(Url::isLocalPath(Url::spelling($in)), $in);
        }
        self::assertTrue(Url::hasTrailingSlash('/blog/'));
        self::assertFalse(Url::hasTrailingSlash('/blog'));
        self::assertFalse(Url::hasTrailingSlash('/'));
        self::assertFalse(Url::hasTrailingSlash(null));
        self::assertSame(Url::key('/blog'), Url::key('/blog/'));
    }

    #[Test]
    public function fromPathDropsTheTrailingAndTheLeadingSlashes(): void
    {
        self::assertSame('/evil.example', Url::fromPath('/\\evil.example/'));
        self::assertSame('/evil.example', Url::fromPath('//evil.example/'));
        self::assertSame('/blog', Url::fromPath('/blog/'));
        self::assertSame('/', Url::fromPath(''));
        self::assertSame('/', Url::fromPath('/'));
    }

    #[Test]
    public function theTrailingSlashRedirectNeverLeavesTheSite(): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Хост', 'url' => '/test-evil-host']);
        $pages->publish($page['id']);

        try {
            foreach (['/%5Ctest-evil-host/', '/%5C%5Ctest-evil-host/', '/%09/test-evil-host/'] as $uri) {
                $this->client->request('GET', $uri);
                $response = $this->client->getResponse();
                self::assertSame(301, $response->getStatusCode(), $uri);
                $location = (string) $response->headers->get('Location');
                self::assertMatchesRegularExpression('~^/(?![/\\\\])~', $location, $uri . ' -> ' . $location);
                self::assertSame('/test-evil-host', $location, $uri);
            }
            // the double slash cannot be written as a relative URI in the test client: an absolute one keeps it as the path
            $this->client->request('GET', 'http://localhost//test-evil-host/');
            self::assertSame(301, $this->client->getResponse()->getStatusCode());
            self::assertSame('/test-evil-host', $this->client->getResponse()->headers->get('Location'));
            // a hostile path that is no page is a plain 404, not a redirect
            $this->client->request('GET', '/%5Cevil.example/');
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
        } finally {
            $pages->remove($page['id']);
        }
    }

    #[Test]
    public function theQueryStringSurvivesTheTrailingSlashRedirect(): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Хост', 'url' => '/test-evil-query']);
        $pages->publish($page['id']);

        try {
            $this->client->request('GET', '/%5Ctest-evil-query/?a=1');
            self::assertSame('/test-evil-query?a=1', $this->client->getResponse()->headers->get('Location'));
        } finally {
            $pages->remove($page['id']);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>}> */
    public static function links(): iterable
    {
        yield 'javascript in page' => [['kind' => 'page', 'page' => 'javascript:alert(document.domain)', 'url' => 'javascript:x'], ['kind' => 'page', 'page' => '/', 'url' => '']];
        yield 'data in page' => [['kind' => 'page', 'page' => 'data:text/html,<script>1</script>', 'url' => ''], ['kind' => 'page', 'page' => '/', 'url' => '']];
        yield 'protocol-relative page' => [['kind' => 'page', 'page' => '//evil.example', 'url' => ''], ['kind' => 'page', 'page' => '/', 'url' => '']];
        yield 'backslash page' => [['kind' => 'page', 'page' => '/\\evil.example', 'url' => ''], ['kind' => 'page', 'page' => '/', 'url' => '']];
        yield 'http in page' => [['kind' => 'page', 'page' => 'https://evil.example/', 'url' => ''], ['kind' => 'page', 'page' => '/', 'url' => '']];
        yield 'scheme with a tab' => [['kind' => 'page', 'page' => "java\tscript:alert(1)", 'url' => ''], ['kind' => 'page', 'page' => '/', 'url' => '']];
        yield 'site path stays' => [['kind' => 'page', 'page' => '/blog/post', 'url' => ''], ['kind' => 'page', 'page' => '/blog/post', 'url' => '']];
        yield 'page id stays' => [['kind' => 'page', 'page' => '0197-abc-DEF_1', 'url' => ''], ['kind' => 'page', 'page' => '0197-abc-DEF_1', 'url' => '']];
        yield 'url kinds' => [['kind' => 'url', 'page' => '/', 'url' => 'https://example.com/a'], ['kind' => 'url', 'page' => '/', 'url' => 'https://example.com/a']];
        yield 'mailto' => [['kind' => 'url', 'page' => '/', 'url' => 'mailto:a@example.com'], ['kind' => 'url', 'page' => '/', 'url' => 'mailto:a@example.com']];
        yield 'javascript in url' => [['kind' => 'url', 'page' => '/', 'url' => 'javascript:alert(1)'], ['kind' => 'url', 'page' => '/', 'url' => '']];
        yield 'backslash in url' => [['kind' => 'url', 'page' => '/', 'url' => '/\\evil.example'], ['kind' => 'url', 'page' => '/', 'url' => '']];
        yield 'not a string' => [['kind' => 'page', 'page' => ['x'], 'url' => ['y']], ['kind' => 'page', 'page' => '/', 'url' => '']];
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, string> $out
     */
    #[Test]
    #[DataProvider('links')]
    public function aLinkFieldKeepsOnlyAddressesThatAreSafe(array $in, array $out): void
    {
        $values = self::getContainer()->get(FieldValues::class);
        $field = Field::link('cta', 'Кнопка');

        self::assertSame($out, $values->normalize([$field], ['cta' => $in])['cta']);
    }

    #[Test]
    public function pageUrlRefusesDangerousAddressesEvenInOldData(): void
    {
        $runtime = self::getContainer()->get(Environment::class)->getRuntime(ItkRuntime::class);

        self::assertSame('/blog', $runtime->pageUrl(['kind' => 'page', 'page' => '/blog', 'url' => '']));
        self::assertSame('https://example.com/x', $runtime->pageUrl(['kind' => 'url', 'url' => 'https://example.com/x']));
        self::assertSame('#', $runtime->pageUrl(['kind' => 'modal']));
        self::assertSame('/', $runtime->pageUrl(['kind' => 'page']), 'no page: the home page, as before');
        foreach ([
            ['kind' => 'page', 'page' => 'javascript:alert(1)'],
            ['kind' => 'page', 'page' => 'data:text/html;base64,PHNjcmlwdD4='],
            ['kind' => 'page', 'page' => '//evil.example'],
            ['kind' => 'page', 'page' => "/\t/evil.example"],
            ['kind' => 'url', 'url' => 'javascript:alert(1)'],
            ['kind' => 'url', 'url' => " JaVa\nScRiPt:alert(1)"],
            ['kind' => 'url', 'url' => 'vbscript:msgbox(1)'],
            ['url' => 'javascript:alert(1)'],
        ] as $stored) {
            $href = $runtime->pageUrl($stored);
            self::assertDoesNotMatchRegularExpression('~script:|^data:|^//~i', \preg_replace('~[\x00-\x20]+~', '', $href) ?? '', \json_encode($stored) . ' -> ' . $href);
        }
        self::assertSame('', $runtime->pageUrl('javascript:alert(1)'));
        self::assertSame('', $runtime->pageUrl('//evil.example'));
        self::assertSame('/catalog', $runtime->pageUrl('/catalog'));
        self::assertSame('', $runtime->pageUrl('no-such-page-id'));
    }

    #[Test]
    public function richTextLinksAndPicturesKeepToTheSameRule(): void
    {
        $html = self::getContainer()->get(HtmlSanitizer::class);

        $out = $html->sanitize('<p><a href="/\\evil.example">a</a> <a href="//evil.example">b</a> <a href="javascript:alert(1)">c</a> <a href="/catalog">d</a></p>');

        self::assertStringNotContainsString('evil.example', $out);
        self::assertStringNotContainsString('javascript', $out);
        self::assertStringContainsString('href="/catalog"', $out);
    }

    #[Test]
    public function aMenuItemKeepsOnlyASafePageLink(): void
    {
        $menus = self::getContainer()->get(MenuService::class);
        $area = $menus->createArea('Test безопасное меню');
        $this->areas[] = $area->key;

        $saved = $menus->saveMenus([$area->key => [
            ['_id' => 'test1', 'label' => 'Раз', 'link' => ['kind' => 'page', 'page' => 'javascript:alert(1)', 'url' => 'javascript:alert(2)']],
            ['_id' => 'test2', 'label' => 'Два', 'link' => ['kind' => 'page', 'page' => '//evil.example', 'url' => '']],
            ['_id' => 'test3', 'label' => 'Три', 'link' => ['kind' => 'page', 'page' => '/catalog', 'url' => '/\\evil.example']],
        ]], 'ru');

        $links = \array_column($saved[$area->key], 'link');
        self::assertSame(['/', '/', '/catalog'], \array_column($links, 'page'));
        self::assertSame(['', '', ''], \array_column($links, 'url'));
    }
}

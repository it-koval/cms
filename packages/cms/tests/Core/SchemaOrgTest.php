<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\SchemaOrg\SchemaOrgInjector;
use Itk\Cms\Tests\App\QueryCounter;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Разметка schema.org на живых страницах: сайт, организация, хлебные крошки, статья блога; выключатель, событие, безопасность JSON внутри script. */
final class SchemaOrgTest extends WebTestCase
{
    use TemporarySettings;

    private KernelBrowser $client;
    /** @var list<string> */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->setting('domain', 'example.com');
        $this->setting('siteName', 'Тестовый сайт');
        foreach (['organizationType', 'organizationName', 'organizationAddress', 'organizationLogo', 'organizationLinks', 'schemaOrgOff'] as $key) {
            $this->setting($key, '');
        }
    }

    protected function tearDown(): void
    {
        $svc = self::getContainer()->get(PageService::class);
        for ($round = 0; $round < 2; ++$round) { // a page that was moved is removed after its new parent's children
            foreach (\array_reverse($this->pages) as $id) {
                try {
                    $svc->remove($id);
                } catch (\Throwable) {
                }
            }
        }
        $this->restoreSettings();
        parent::tearDown();
    }

    /** @param array<string, mixed> $fields */
    private function page(string $template, string $title, string $url, ?string $parentId = null, array $fields = [], bool $live = true): string
    {
        $svc = self::getContainer()->get(PageService::class);
        $page = $svc->create(['template' => $template, 'title' => $title, 'url' => $url, 'parentId' => $parentId, 'fields' => $fields]);
        $this->pages[] = $page['id'];
        if ($live) {
            $svc->publish($page['id']);
        }

        return $page['id'];
    }

    private function html(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<string> the raw bodies of the ld+json blocks the CMS put before the first </head> */
    private function blocks(string $html): array
    {
        \preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);

        return $m[1];
    }

    /** @return array<string, array<string, mixed>> the nodes of the CMS graph by @var */
    private function graph(string $url): array
    {
        $blocks = $this->blocks($this->html($url));
        self::assertNotSame([], $blocks, 'the page has no ld+json');
        $data = \json_decode(\end($blocks), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('https://schema.org', $data['@context']);
        $nodes = [];
        foreach ($data['@graph'] as $node) {
            $nodes[$node['@type']] = $node;
        }

        return $nodes;
    }

    #[Test]
    public function aPageGetsTheWebSiteAndTheOrganizationFromTheSettings(): void
    {
        $this->setting('organizationName', 'ООО «Ромашка»');
        $this->setting('phone', '+7 (900) 000-00-00');
        $this->setting('email', 'info@example.com');
        $this->setting('organizationAddress', 'Москва, ул. Тестовая, 1');
        $this->setting('organizationLinks', "https://t.me/romashka\nhttp://vk.com/romashka\njavascript:alert(1)\nромашка.рф\n\n  https://ok.ru/romashka  ");
        $this->page('default', 'Главная', '/test-ld-home');

        $g = $this->graph('/test-ld-home');

        self::assertSame(['@id' => 'https://example.com/#website', '@type' => 'WebSite', 'url' => 'https://example.com/', 'name' => 'Тестовый сайт', 'inLanguage' => 'ru', 'publisher' => ['@id' => 'https://example.com/#organization']], $g['WebSite']);
        $org = $g['Organization'];
        self::assertSame('https://example.com/#organization', $org['@id']);
        self::assertSame('ООО «Ромашка»', $org['name']);
        self::assertSame('https://example.com/', $org['url']);
        self::assertSame('+7 (900) 000-00-00', $org['telephone']);
        self::assertSame('info@example.com', $org['email']);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => 'Москва, ул. Тестовая, 1'], $org['address']);
        self::assertSame(['https://t.me/romashka', 'http://vk.com/romashka', 'https://ok.ru/romashka'], $org['sameAs']);
        self::assertArrayNotHasKey('logo', $org);
    }

    #[Test]
    public function theOrganizationNameFallsBackToTheSiteNameAndEmptyValuesAreOmitted(): void
    {
        $this->setting('phone', '');
        $this->setting('email', '');
        $this->page('default', 'Главная', '/test-ld-fallback');

        $org = $this->graph('/test-ld-fallback')['Organization'];

        self::assertSame('Тестовый сайт', $org['name']);
        self::assertSame(['@id', '@type', 'name', 'url'], \array_keys($org));
    }

    #[Test]
    public function aLocalBusinessIsWrittenWhenChosenAndTheLogoIsAnAbsoluteUrl(): void
    {
        $this->setting('organizationType', 'LocalBusiness');
        $this->setting('organizationAddress', 'Москва, ул. Тестовая, 1');
        $this->setting('organizationLogo', ['src' => '/uploads/media/logo.png', 'alt' => '']);
        $this->page('default', 'Главная', '/test-ld-local');

        $g = $this->graph('/test-ld-local');

        self::assertArrayNotHasKey('Organization', $g);
        self::assertSame('https://example.com/uploads/media/logo.png', $g['LocalBusiness']['logo']);
        self::assertSame('https://example.com/#organization', $g['LocalBusiness']['@id']);
    }

    #[Test]
    public function aNestedPageHasBreadcrumbsFromTheRootDownAndARootPageHasNone(): void
    {
        $a = $this->page('default', 'Услуги', '/test-ld-a');
        $b = $this->page('default', 'Ремонт', '/test-ld-a/b', $a);
        $this->page('default', 'Окна', '/test-ld-a/b/c', $b);

        $crumbs = $this->graph('/test-ld-a/b/c')['BreadcrumbList']['itemListElement'];

        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Услуги', 'item' => 'https://example.com/test-ld-a'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Ремонт', 'item' => 'https://example.com/test-ld-a/b'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Окна', 'item' => 'https://example.com/test-ld-a/b/c'],
        ], $crumbs);
        self::assertArrayNotHasKey('BreadcrumbList', $this->graph('/test-ld-a'));
    }

    #[Test]
    public function aBlogArticleGetsABlogPosting(): void
    {
        $blog = $this->page('blog', 'Блог', '/test-ld-blog');
        $this->page('blog_article', 'Как выбрать окна', '/test-ld-blog/windows', $blog, [
            'title' => 'Как выбрать окна', 'date' => '2026-05-01', 'cover' => ['src' => '/uploads/media/cover.jpg', 'alt' => ''], 'excerpt' => 'Коротко о главном', 'body' => 'Текст статьи',
        ]);

        $g = $this->graph('/test-ld-blog/windows');
        $post = $g['BlogPosting'];

        self::assertSame('Как выбрать окна', $post['headline']);
        self::assertSame('2026-05-01', $post['datePublished']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $post['dateModified']);
        self::assertSame('https://example.com/uploads/media/cover.jpg', $post['image']);
        self::assertSame('Коротко о главном', $post['description']);
        self::assertSame('https://example.com/test-ld-blog/windows', $post['mainEntityOfPage']);
        self::assertSame(['@id' => 'https://example.com/#organization'], $post['author']);
        self::assertSame(['@id' => 'https://example.com/#organization'], $post['publisher']);
        self::assertCount(2, $g['BreadcrumbList']['itemListElement']);
    }

    #[Test]
    public function aTitleCannotBreakOutOfTheScriptElement(): void
    {
        $title = 'Тест </script><script>alert(1)</script> & "кавычки"';
        $this->page('default', $title, '/test-ld-xss');

        $html = $this->html('/test-ld-xss');
        $start = \strpos($html, '<script type="application/ld+json">');
        self::assertNotFalse($start);
        $end = \strpos($html, '</script>', $start);
        $body = \substr($html, $start + 35, $end - $start - 35);

        self::assertStringNotContainsString('</script', $body);
        self::assertStringNotContainsString('<', $body);
        self::assertStringContainsString('Тест', $body, 'Cyrillic stays readable');
        self::assertNotNull(\json_decode($body, true), 'the block is still valid JSON');
        self::assertStringNotContainsString('<script>alert(1)', $html);
    }

    #[Test]
    public function aLayoutThatPrintsItsOwnOrganizationDoesNotGetASecondOne(): void
    {
        $this->page('default', 'Главная', '/test-ld-own');

        $html = $this->html('/test-ld-own');
        $blocks = $this->blocks($html);

        self::assertCount(2, $blocks);
        $types = \array_column(\json_decode($blocks[1], true)['@graph'], '@type');
        self::assertSame(['WebSite'], $types);
        self::assertSame(1, \substr_count($html, '"Organization"'));
    }

    #[Test]
    public function theOffSwitchAndAMissingDomainGiveNoBlock(): void
    {
        $this->page('default', 'Главная', '/test-ld-off');

        $this->setting('schemaOrgOff', true);
        self::assertSame([], $this->blocks($this->html('/test-ld-off')));

        $this->setting('schemaOrgOff', false);
        self::assertNotSame([], $this->blocks($this->html('/test-ld-off')));

        $this->setting('domain', '');
        self::assertSame([], $this->blocks($this->html('/test-ld-off')));
    }

    #[Test]
    public function thePreviewHasNoBlock(): void
    {
        $id = $this->page('default', 'Главная', '/test-ld-preview');

        $requests = self::getContainer()->get(RequestStack::class);
        $requests->push(Request::create('http://localhost/test-ld-preview'));
        try {
            $html = (string) self::getContainer()->get(PageRenderer::class)->renderPreview($id)->getContent();
        } finally {
            $requests->pop();
        }

        self::assertStringNotContainsString('application/ld+json', $html);
    }

    #[Test]
    public function aListenerOfTheSiteChangesTheGraph(): void
    {
        $this->page('default', 'Событие', '/test-ld-event');

        $g = $this->graph('/test-ld-event');

        self::assertSame('Тестовый товар', $g['Product']['name']);
        self::assertArrayNotHasKey('WebSite', $g);
        self::assertArrayHasKey('Organization', $g);
    }

    #[Test]
    public function breadcrumbsDoNotCostAQueryPerAncestor(): void
    {
        $shallow = $this->page('default', 'Уровень 1', '/test-ld-q1');
        $deep = $this->page('default', 'Уровень 1', '/test-ld-q2');
        foreach ([2, 3, 4] as $i) {
            $deep = $this->page('default', 'Уровень ' . $i, '/test-ld-q2/l' . $i, $deep);
        }
        $leaf = $this->page('default', 'Лист', '/test-ld-q1/leaf', $shallow);

        $this->queriesOf($leaf, '/test-ld-q1/leaf'); // the first render of a process loads the settings
        $shallowQueries = $this->queriesOf($leaf, '/test-ld-q1/leaf');
        $deepQueries = $this->queriesOf($deep, '/test-ld-q2/l2/l3/l4');

        self::assertSame(\count($shallowQueries), \count($deepQueries), \implode("\n", $deepQueries));
    }

    #[Test]
    public function aChainBrokenByANonLivePageGivesNoBreadcrumbsAndMovingThatPageChangesNothing(): void
    {
        $a = $this->page('default', 'А', '/test-ld-ba');
        $b = $this->page('default', 'Б', '/test-ld-ba/b', $a, [], false);
        $this->page('default', 'В', '/test-ld-ba/b/c', $b);
        $d = $this->page('default', 'Д', '/test-ld-bd');

        self::assertArrayNotHasKey('BreadcrumbList', $this->graph('/test-ld-ba/b/c'));
        $before = $this->html('/test-ld-ba/b/c');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(Page::class)->find($b)->parent = $em->getReference(Page::class, $d);
        $em->flush();

        self::assertSame($before, $this->html('/test-ld-ba/b/c'));
    }

    #[Test]
    public function noReferenceDanglesWhenTheOrganizationIsLeftOut(): void
    {
        $blog = $this->page('blog', 'Блог', '/test-ld-own-blog');
        $this->page('blog_article', 'Статья', '/test-ld-own', $blog, ['title' => 'Статья', 'body' => 'Текст']);

        $nodes = \json_decode($this->blocks($this->html('/test-ld-own'))[1], true)['@graph'];

        self::assertSame(['WebSite', 'BreadcrumbList', 'BlogPosting'], \array_column($nodes, '@type'));
        self::assertStringNotContainsString('#organization', (string) \json_encode($nodes));
    }

    #[Test]
    public function aLocalBusinessWithoutAnAddressIsAnOrganization(): void
    {
        $this->setting('organizationType', 'LocalBusiness');
        $this->setting('organizationAddress', '');
        $this->page('default', 'Главная', '/test-ld-nolocal');

        self::assertArrayHasKey('Organization', $this->graph('/test-ld-nolocal'));
    }

    #[Test]
    public function theDatePublishedStaysWhenThePageIsPublishedAgain(): void
    {
        $blog = $this->page('blog', 'Блог', '/test-ld-dp');
        $id = $this->page('blog_article', 'Статья', '/test-ld-dp/a', $blog, ['title' => 'Статья', 'body' => 'Текст']);
        $first = $this->graph('/test-ld-dp/a')['BlogPosting']['datePublished'];
        \usleep(1_100_000);

        self::getContainer()->get(PageService::class)->publish($id);

        self::assertSame($first, $this->graph('/test-ld-dp/a')['BlogPosting']['datePublished']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function ownMarkup(): iterable
    {
        yield 'a subtype of organization' => ['Store', '{"@context":"https://schema.org","@type":"Store","name":"S"}', 'Organization'];
        yield 'another kind of article' => ['NewsArticle', '{"@context":"https://schema.org","@type":"NewsArticle","headline":"H"}', 'BlogPosting'];
        yield 'a nested breadcrumb' => ['WebPage', '{"@context":"https://schema.org","@type":"WebPage","breadcrumb":{"@type":"BreadcrumbList","itemListElement":[]}}', 'BreadcrumbList'];
    }

    #[Test]
    #[DataProvider('ownMarkup')]
    public function aTypeOfTheSameKindInTheLayoutHidesOurs(string $own, string $json, string $ours): void
    {
        $html = '<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>';

        $found = SchemaOrgInjector::typesIn($html);

        self::assertContains($own, $found);
        self::assertTrue(SchemaOrgInjector::isCovered($ours, $found), $ours . ' is covered by ' . $own);
        self::assertFalse(SchemaOrgInjector::isCovered('WebSite', $found));
    }

    /** @return list<string> */
    private function queriesOf(string $id, string $url): array
    {
        $c = self::getContainer();
        $c->get(EntityManagerInterface::class)->clear();
        $c->get(\Itk\Cms\Content\LiveUrlIndex::class)->reset();
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertInstanceOf(PageTranslation::class, $t);
        $requests = $c->get(RequestStack::class);
        $requests->push(Request::create('http://localhost' . $url));
        try {
            $queries = QueryCounter::during(static function () use ($c, $t): void {
                $c->get(PageRenderer::class)->renderLive($t);
            });
        } finally {
            $requests->pop();
        }

        return $queries;
    }
}

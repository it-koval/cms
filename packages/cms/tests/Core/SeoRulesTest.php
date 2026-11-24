<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\RedirectService;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/** SEO для любого адреса: правила (точный адрес и «всё внутри», строка запроса, язык), итог в страницах и в itk_seo(), API и проверка адреса. */
final class SeoRulesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    /** @var list<string> */
    private array $pages = [];
    private ?string $redirectId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $user = self::getContainer()->get(UserManager::class)->create('test-seo@example.com', 'Тест SEO', 'admin', 'password-for-test');
        $this->signIn($user);
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $em->createQuery("DELETE FROM Itk\\Cms\\Entity\\SeoRule r WHERE r.path LIKE '/test-%'")->execute();
        if (null !== $this->redirectId) {
            try {
                $c->get(RedirectService::class)->remove($this->redirectId);
            } catch (\Throwable) {
            }
        }
        foreach ($this->pages as $id) {
            try {
                $c->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf];
        $this->client->request($method, '/admin/api/' . $path, [], [], $server, [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function rules(): SeoRuleService
    {
        return self::getContainer()->get(SeoRuleService::class);
    }

    /**
     * A published page at $url; $seo is its own SEO.
     *
     * @param array<array-key, mixed> $seo
     */
    private function page(string $title, string $url, array $seo = []): string
    {
        $svc = self::getContainer()->get(PageService::class);
        $page = $svc->create(['template' => 'default', 'title' => $title, 'url' => $url]);
        $this->pages[] = $page['id'];
        if ([] !== $seo) {
            $svc->saveDraft($page['id'], null, ['seo' => $seo] + $svc->getDraft($page['id'])['page']);
        }
        $svc->publish($page['id']);

        return $page['id'];
    }

    private function html(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function resolver(): SeoResolver
    {
        return self::getContainer()->get(SeoResolver::class);
    }

    #[Test]
    public function redirectsMatchTheQueryUnlessAPathRuleTakesPriority(): void
    {
        $this->api('POST', 'redirects', ['from' => '/f2-redirect-query', 'to' => '/f2-redirect-query?ref=Summer'], 422);
        $redirects = self::getContainer()->get(RedirectService::class);
        $specific = $redirects->create('/f2-redirect-query?ref=Summer&id=4', '/f2-specific');
        $general = null;
        try {
            $this->client->request('GET', '/f2-redirect-query?id=4&ref=Summer');
            self::assertResponseRedirects('/f2-specific', 301);
            $this->client->request('GET', '/f2-redirect-query?id=4&ref=summer');
            self::assertResponseStatusCodeSame(404);
            $check = $this->api('GET', 'seo-rules/check?path=' . \urlencode('/f2-redirect-query?id=4&ref=Summer'));
            self::assertSame('/f2-specific', $check['answer']['to'] ?? null);

            $general = $redirects->create('/f2-redirect-query', '/f2-general');
            $this->client->request('GET', '/f2-redirect-query?id=4&ref=Summer');
            self::assertResponseRedirects('/f2-general', 301);
            $this->client->request('GET', '/f2-redirect-query');
            self::assertResponseRedirects('/f2-general', 301);
        } finally {
            $redirects->remove($specific->id);
            if (null !== $general) {
                $redirects->remove($general->id);
            }
        }
    }

    #[Test]
    public function theMostSpecificRuleWins(): void
    {
        $this->rules()->create(['path' => '/test-blog', 'match' => 'prefix', 'title' => 'Весь раздел']);
        $exact = $this->rules()->create(['path' => '/test-blog/post', 'match' => 'exact', 'title' => 'Одна статья']);
        $deep = $this->rules()->create(['path' => '/test-blog/post/more', 'match' => 'prefix', 'title' => 'Глубже']);
        $query = $this->rules()->create(['path' => '/test-blog/post?page=2', 'match' => 'exact', 'title' => 'Вторая страница']);
        $r = $this->resolver();

        self::assertSame('Весь раздел', $r->best('/test-blog')?->title);
        self::assertSame('Весь раздел', $r->best('/test-blog/other')?->title);
        self::assertSame($exact->id, $r->best('/test-blog/post', ['page' => '1'])?->id, 'the query of the rule must be in the request');
        self::assertSame($query->id, $r->best('/test-blog/post', ['page' => '2', 'utm_source' => 'x'])?->id, 'other parameters are ignored');
        self::assertSame($deep->id, $r->best('/test-blog/post/more/x')?->id, 'a longer prefix beats a shorter one');
        self::assertNull($r->best('/test-blogger'), 'a prefix covers whole segments only');
        self::assertSame([$query->id, $exact->id], \array_slice(\array_map(static fn ($x) => $x->id, $r->matches('/test-blog/post', ['page' => '2'])), 0, 2));
    }

    #[Test]
    public function theAddressIsComparedWithoutCaseAndTrailingSlash(): void
    {
        $this->rules()->create(['path' => '/TEST-Case/', 'title' => 'Регистр']);

        self::assertSame('/test-case', $this->resolver()->best('/test-case')?->path);
        self::assertSame('Регистр', $this->resolver()->best('/TEST-CASE/')?->title);
    }

    #[Test]
    public function aRuleOfALanguageFitsThatLanguageOnly(): void
    {
        $this->rules()->create(['path' => '/test-lang', 'locale' => 'en', 'title' => 'English']);
        $all = $this->rules()->create(['path' => '/test-lang', 'title' => 'Для всех']);

        self::assertSame('English', $this->resolver()->best('/test-lang', [], 'en')?->title, 'the rule of the language itself is preferred');
        self::assertSame($all->id, $this->resolver()->best('/test-lang', [], 'ru')?->id);
    }

    #[Test]
    public function aSwitchedOffRuleChangesNothing(): void
    {
        $rule = $this->rules()->create(['path' => '/test-off', 'title' => 'Выключено']);
        $this->rules()->update($rule->id, ['active' => false]);

        self::assertNull($this->resolver()->best('/test-off'));

        $this->rules()->update($rule->id, ['active' => true]);
        self::assertNotNull($this->resolver()->best('/test-off'));
    }

    #[Test]
    public function aPageWithoutRulesRendersItsOwnTags(): void
    {
        $this->page('Страница без правил', '/test-seo-plain', ['title' => 'Свой заголовок', 'description' => 'Своё описание']);

        $html = $this->html('/test-seo-plain');

        self::assertStringContainsString('<title>Свой заголовок</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Своё описание"/>', $html);
        self::assertStringContainsString('<meta name="robots" content="index,follow"/>', $html);
        self::assertSame(1, \substr_count($html, 'rel="canonical"'));
        self::assertStringContainsString('/test-seo-plain"/>', $html);
        self::assertStringNotContainsString('og:image', $html);
    }

    #[Test]
    public function theRuleOverridesOnlyWhatItFillsIn(): void
    {
        $this->page('Страница с правилом', '/test-seo-rule', ['title' => 'Заголовок страницы', 'description' => 'Описание страницы']);
        $this->rules()->create([
            'path' => '/test-seo-rule', 'title' => 'Заголовок правила', 'robots' => 'noindex,follow',
            'canonical' => '/test-seo-main', 'ogImage' => '/uploads/test-cover.jpg',
        ]);

        $html = $this->html('/test-seo-rule');

        self::assertStringContainsString('<title>Заголовок правила</title>', $html);
        self::assertStringContainsString('content="Описание страницы"', $html, 'the description is not in the rule: the page keeps its own');
        self::assertStringContainsString('<meta name="robots" content="noindex,follow"/>', $html);
        self::assertMatchesRegularExpression('~<link rel="canonical" href="https?://[^/"]+/test-seo-main"/>~', $html);
        self::assertSame(1, \substr_count($html, 'rel="canonical"'));
        self::assertMatchesRegularExpression('~<meta property="og:image" content="https?://[^/"]+/uploads/test-cover\.jpg"/>~', $html);
        self::assertStringContainsString('<meta property="og:title" content="Заголовок правила"/>', $html);
    }

    #[Test]
    public function aPrefixRuleCoversThePagesBelow(): void
    {
        $this->page('Вложенная', '/test-seo-section/inner');
        $this->rules()->create(['path' => '/test-seo-section', 'match' => 'prefix', 'robots' => 'noindex,nofollow']);

        self::assertStringContainsString('content="noindex,nofollow"', $this->html('/test-seo-section/inner'));
    }

    #[Test]
    public function itkSeoWorksForAnAddressWithoutAPage(): void
    {
        $this->rules()->create(['path' => '/test-seo-route?page=2', 'title' => 'Каталог, страница 2', 'description' => 'Вторая страница каталога']);
        $c = self::getContainer();
        $twig = $c->get(Environment::class);
        $stack = $c->get(RequestStack::class);
        $template = $twig->createTemplate("{% set s = itk_seo({title: 'Сайт', robots: 'index,follow'}) %}{{ s.title }}|{{ s.description }}|{{ s.robots }}|{{ s.canonical }}|{{ s.rule ? 'rule' : 'none' }}");

        $stack->push(Request::create('/test-seo-route?page=2&utm_source=a'));
        $with = $template->render([]);
        $stack->pop();
        $stack->push(Request::create('/test-seo-route'));
        $without = $template->render([]);
        $stack->pop();

        self::assertSame('Каталог, страница 2|Вторая страница каталога|index,follow||rule', $with);
        self::assertSame('Сайт||index,follow||none', $without, 'no rule: the defaults of the layout');
    }

    #[Test]
    public function relativeAddressesBecomeFullOnes(): void
    {
        $this->rules()->create(['path' => '/test-seo-full', 'canonical' => '/test-target', 'ogImage' => 'https://cdn.example.com/a.jpg']);
        $twig = self::getContainer()->get(Environment::class);
        self::getContainer()->get(RequestStack::class)->push(Request::create('https://site.example.com/test-seo-full'));

        $stack = self::getContainer()->get(RequestStack::class);
        $out = $twig->createTemplate('{{ itk_seo().canonical }}|{{ itk_seo().ogImage }}')->render([]);
        $stack->pop();

        self::assertSame('https://site.example.com/test-target|https://cdn.example.com/a.jpg', $out);
    }

    #[Test]
    public function theApiCreatesChangesSearchesAndRemoves(): void
    {
        $row = $this->api('POST', 'seo-rules', ['path' => '/TEST-Api/?b=2&a=1', 'title' => 'API', 'robots' => 'noindex,follow'], 201);
        self::assertSame('/test-api?a=1&b=2', $row['path'], 'the address is normalized');
        self::assertSame('exact', $row['match']);
        self::assertTrue($row['active']);
        self::assertNull($row['locale']);

        $this->api('POST', 'seo-rules', ['path' => '/test-api?a=1&b=2', 'title' => 'Ещё раз'], 422);

        $changed = $this->api('PATCH', 'seo-rules/' . $row['id'], ['title' => 'API 2', 'match' => 'exact', 'active' => false]);
        self::assertSame('API 2', $changed['title']);
        self::assertFalse($changed['active']);

        $found = $this->api('GET', 'seo-rules?q=' . \urlencode('API 2'));
        self::assertContains($row['id'], \array_column($found, 'id'));
        self::assertNotContains($row['id'], \array_column($this->api('GET', 'seo-rules?q=test-nothing-like-this'), 'id'));

        $this->api('DELETE', 'seo-rules/' . $row['id']);
        $this->api('PATCH', 'seo-rules/' . $row['id'], ['title' => 'x'], 404);
    }

    #[Test]
    public function theApiRefusesBadRules(): void
    {
        $bad = [
            'no domain' => ['path' => 'https://example.com/test-a', 'title' => 'x'],
            'no slash' => ['path' => 'test-a', 'title' => 'x'],
            'a space' => ['path' => '/test a', 'title' => 'x'],
            'empty rule' => ['path' => '/test-a'],
            'unknown robots' => ['path' => '/test-a', 'robots' => 'maybe'],
            'prefix with a query' => ['path' => '/test-a?x=1', 'match' => 'prefix', 'title' => 'x'],
            'unknown match' => ['path' => '/test-a', 'match' => 'regex', 'title' => 'x'],
            'a script as the image' => ['path' => '/test-a', 'ogImage' => 'javascript:alert(1)'],
            'too long a title' => ['path' => '/test-a', 'title' => \str_repeat('я', 256)],
        ];
        foreach ($bad as $why => $body) {
            $res = $this->api('POST', 'seo-rules', $body, 422);
            self::assertNotEmpty($res['details'] ?? null, $why);
        }
        self::assertSame([], \array_filter($this->api('GET', 'seo-rules'), static fn (array $r): bool => \str_starts_with($r['path'], '/test-a')));
    }

    #[Test]
    public function theCheckSaysWhatAnAddressAnswersAndWhereTheTagsComeFrom(): void
    {
        $this->page('Для проверки', '/test-seo-check', ['title' => 'Заголовок из страницы', 'description' => 'Описание из страницы']);
        $this->rules()->create(['path' => '/test-seo-check', 'title' => 'Заголовок из правила']);

        $page = $this->api('GET', 'seo-rules/check?path=' . \urlencode('/test-seo-check'));
        self::assertSame('/test-seo-check', $page['path']);
        self::assertSame('page', $page['answer']['kind']);
        self::assertSame(200, $page['answer']['status']);
        self::assertSame(['value' => 'Заголовок из правила', 'source' => 'rule'], $page['meta']['title']);
        self::assertSame(['value' => 'Описание из страницы', 'source' => 'page'], $page['meta']['description']);
        self::assertSame('none', $page['meta']['robots']['source']);
        self::assertTrue($page['rules'][0]['winner']);

        $route = $this->api('GET', 'seo-rules/check?path=/admin/login');
        self::assertSame('route', $route['answer']['kind']);

        $missing = $this->api('GET', 'seo-rules/check?path=/test-seo-nowhere');
        self::assertSame('missing', $missing['answer']['kind']);
        self::assertSame(404, $missing['answer']['status']);
        self::assertNull($missing['meta']);

        $redirect = self::getContainer()->get(RedirectService::class)->create('/test-seo-moved', '/test-seo-check', 301);
        $this->redirectId = $redirect->id;
        $moved = $this->api('GET', 'seo-rules/check?path=/test-seo-moved');
        self::assertSame('redirect', $moved['answer']['kind']);
        self::assertSame(301, $moved['answer']['status']);
        self::assertSame('/test-seo-check', $moved['answer']['to']);

        $this->api('GET', 'seo-rules/check?path=nope', [], 422);
    }

    #[Test]
    public function aRoleWithoutTheAreaCannotUseTheApi(): void
    {
        $limited = self::getContainer()->get(UserManager::class)->create('test-seo-limited@example.com', 'Узкий', 'requests', 'password-for-test');
        $this->signIn($limited);

        $this->api('GET', 'seo-rules', [], 403);
        $this->api('GET', 'seo-rules/check?path=/', [], 403);
        $this->api('POST', 'seo-rules', ['path' => '/test-denied', 'title' => 'x'], 403);
        $this->api('DELETE', 'seo-rules/anything', [], 403);
    }

    #[Test]
    public function theAreaIsInThePermissionMatrixOfTheSchema(): void
    {
        $areas = \array_column($this->api('GET', 'schema')['permissionAreas'], null, 'key');

        self::assertSame(['view', 'edit', 'delete'], $areas['set:seo']['actions']);
        self::assertSame('settings', $areas['set:seo']['group']);
    }
}

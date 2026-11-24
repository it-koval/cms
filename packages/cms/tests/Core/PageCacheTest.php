<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Command\ClearPageCacheCommand;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\User;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Rendering\PageValidators;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Support\Url;
use Itk\Cms\Tests\App\QueryCounter;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;

/** Готовая страница лежит в кэше: повторный показ идёт без рендера, а любая правка, которую видит отпечаток, видна сразу. */
final class PageCacheTest extends WebTestCase
{
    /** @var list<string> */
    private array $created = [];
    /** @var list<string> */
    private array $items = [];
    /** @var list<string> */
    private array $files = [];
    /** @var list<string> */
    private array $rules = [];
    private bool $domainTouched = false;
    private bool $domainWasSet = false;
    private mixed $domainBefore = '';

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', $options['debug'] ?? false, null, '', [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        foreach ($this->created as $id) {
            try {
                $c->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        foreach ($this->items as $id) {
            try {
                $c->get(CollectionService::class)->remove('tours', $id);
            } catch (\Throwable) {
            }
        }
        foreach ($this->rules as $id) {
            try {
                $c->get(SeoRuleService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        foreach ($this->files as $id) {
            $c->get(Connection::class)->executeStatement('DELETE FROM itk_media_file WHERE id = ?', [$id]);
        }
        $c->get(SettingsService::class)->set('requestButtonText', null);
        if ($this->domainTouched) {
            if ($this->domainWasSet) {
                $c->get(SettingsService::class)->set('domain', $this->domainBefore);
            } else {
                $c->get(EntityManagerInterface::class)->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'domain'")->execute();
            }
        }
        $c->get(MenuService::class)->saveMenus(['header' => []]);
        $c->get(PageCache::class)->clear();
        $c->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.email LIKE :e')->setParameter('e', 'test-pc-%@example.com')->execute();
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->catchExceptions(false);
        self::getContainer()->get(PageCache::class)->clear();
        $this->vouch('localhost');

        return $client;
    }

    /** The host the site answers for («Настройки → Сайт»): the test client asks for localhost unless the url says otherwise. */
    private function vouch(string $domain): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        if (!$this->domainTouched) {
            $this->domainTouched = true;
            $this->domainWasSet = $settings->isSet('domain');
            $this->domainBefore = $settings->get('domain', null, '');
        }
        $settings->set('domain', $domain);
    }

    /** A cache without a kernel behind it, but with the real site address setting. */
    private function cache(CacheItemPoolInterface $pool, int $ttl = 3600, ClockInterface $clock = new MockClock()): PageCache
    {
        return new PageCache($pool, true, false, $ttl, $clock, self::getContainer()->get(SitemapBuilder::class));
    }

    /** A page whose layout asks the cache for `$layout` seconds and whose block for `$block` ('off' = do not keep, 'none' = no call): see tests/App/templates/layout.html.twig. */
    private function probe(string $layout, string $block = 'none'): string
    {
        $url = '/test-pc-probe-' . $layout . '-' . $block;
        $this->publish('Для кеша', $url);

        return $url;
    }

    /** Is there an entry for this page in the pool, under the key the request would use? */
    private function stored(string $id, string $url): bool
    {
        $c = self::getContainer();
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $request = Request::create($url);
        $fingerprint = $c->get(PageValidators::class)->fingerprint($t, $request);
        self::assertNotNull($fingerprint);

        return null !== $c->get(PageCache::class)->get($request, $fingerprint);
    }

    private function publish(string $title, string $url): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => $title, 'url' => $url]);
        $this->created[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    private function visit(KernelBrowser $client, string $url, string $method = 'GET', array $server = [], array $cookies = []): Response
    {
        foreach ($cookies as $name => $value) {
            $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value));
        }
        $client->request($method, $url, [], [], $server);

        return $client->getResponse();
    }

    private function cacheOf(Response $response): string
    {
        return (string) $response->headers->get('X-ITK-Cache');
    }

    #[Test]
    public function theSecondVisitIsAHitWithoutARender(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc');

        $first = $this->visit($client, '/test-pc');
        self::assertSame('MISS', $this->cacheOf($first));
        self::assertStringContainsString('Для кеша', (string) $first->getContent());
        $firstBody = (string) $first->getContent();

        $queries = QueryCounter::during(function () use ($client, &$second): void {
            $second = $this->visit($client, '/test-pc');
        });

        self::assertSame('HIT', $this->cacheOf($second));
        self::assertSame($firstBody, (string) $second->getContent());
        self::assertSame(200, $second->getStatusCode());
        self::assertStringContainsString('text/html', (string) $second->headers->get('Content-Type'));
        self::assertNotSame('', (string) $second->headers->get('ETag'), 'a hit carries the validators like a drawn page');
        self::assertNotSame('', (string) $second->headers->get('Last-Modified'));
        self::assertStringContainsString('no-cache', (string) $second->headers->get('Cache-Control'));
        self::assertLessThanOrEqual(5, \count($queries), 'the fingerprint and the site address: ' . \implode("\n", $queries));
    }

    #[Test]
    public function aChangeOfContentIsAMissAtOnce(): void
    {
        $client = $this->client();
        $c = self::getContainer();
        $id = $this->publish('Для кеша', '/test-pc-change');
        $this->visit($client, '/test-pc-change');
        /** @var \Closure(string): Response $missed */
        $missed = function (string $what) use ($client): Response {
            $answer = $this->visit($client, '/test-pc-change');
            self::assertSame('MISS', $this->cacheOf($answer), $what . ' must be a miss');
            self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-change')), 'and the next visit is a hit again');

            return $answer;
        };
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-change')), 'nothing changed: a hit');

        $c->get(PageService::class)->update($id, 'ru', ['seo' => ['title' => 'Новый заголовок']]);
        $c->get(PageService::class)->publish($id);
        self::assertStringContainsString('Новый заголовок', (string) $missed('the page itself published again')->getContent());

        $this->publish('Соседняя', '/test-pc-change-other');
        $missed('another page that goes live');

        $c->get(SettingsService::class)->set('requestButtonText', 'Позвоните мне');
        $missed('a setting');

        $c->get(MenuService::class)->syncSchemaAreas();
        $c->get(MenuService::class)->saveMenus(['header' => [['label' => 'Пункт', 'link' => ['kind' => 'url', 'url' => '/x']]]]);
        $missed('a menu');

        $file = new MediaFile('/uploads/media/test-pc.jpg', 'test-pc.jpg', 'image/jpeg');
        $this->files[] = $file->id;
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($file);
        $em->flush();
        $c->get(MediaService::class)->update($file->id, ['crops' => ['16:9' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.5]]]);
        $missed('a frame of a picture');

        $item = $c->get(CollectionService::class)->create('tours', ['city' => 'Тур для кеша', 'dateStart' => '2026-11-01', 'dateEnd' => '2026-11-09']);
        $this->items[] = $item['id'];
        $c->get(CollectionService::class)->publish('tours', $item['id']);
        $missed('a record of a collection that goes live');

        $rule = $c->get(SeoRuleService::class)->create(['path' => '/test-pc-change', 'title' => 'Правило']);
        $this->rules[] = $rule->id;
        $missed('a SEO rule');

        $c->get(Connection::class)->executeStatement('UPDATE itk_setting SET setting_value = setting_value WHERE setting_key = ?', ['requestButtonText']);
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-change')), 'a row rewritten with the same value: nothing to show');
        $c->get(Connection::class)->executeStatement('UPDATE itk_setting SET setting_value = ? WHERE setting_key = ?', [\json_encode('Через базу'), 'requestButtonText']);
        $missed('a row changed straight in the database');
    }

    #[Test]
    public function theSchemeIsPartOfTheEntry(): void
    {
        $client = $this->client();
        $this->vouch('a.test');
        $this->publish('Для кеша', '/test-pc-key');
        $urls = ['http://a.test/test-pc-key', 'https://a.test/test-pc-key'];

        foreach ($urls as $url) {
            self::assertSame('MISS', $this->cacheOf($this->visit($client, $url)), $url . ' is its own entry');
        }
        foreach ($urls as $url) {
            self::assertSame('HIT', $this->cacheOf($this->visit($client, $url)), $url . ' is kept');
        }
        $body = (string) $this->visit($client, 'https://a.test/test-pc-key')->getContent();
        self::assertStringContainsString('https://a.test/test-pc-key', $body, 'the canonical uses the configured domain origin');
        self::assertStringNotContainsString('http://a.test', $body);
    }

    #[Test]
    public function aQueryStringBypasses(): void
    {
        $client = $this->client();
        $id = $this->publish('Для кеша', '/test-pc-query');

        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-query?x=1')));
        $again = $this->visit($client, '/test-pc-query?x=1');
        self::assertSame('BYPASS', $this->cacheOf($again), 'it is not kept the first time, so it is not read the second');
        self::assertStringContainsString('Для кеша', (string) $again->getContent(), 'drawn as usual');
        self::assertNotSame('', (string) $again->headers->get('ETag'), 'the tag of the page stays');
        self::assertFalse($this->stored($id, '/test-pc-query?x=1'), 'nothing was stored for the query');
        self::assertFalse($this->stored($id, '/test-pc-query'), 'nor for the page without it');

        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-query')));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-query')));
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-query?x=2')), 'a plain entry does not answer a request with a query');
        self::assertTrue($this->stored($id, '/test-pc-query'));
    }

    #[Test]
    public function aQueryStringKeepsTheConditionalAnswer(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-query-304');
        $etag = (string) $this->visit($client, '/test-pc-query-304?x=1')->headers->get('ETag');
        self::assertNotSame('', $etag);

        $answer = $this->visit($client, '/test-pc-query-304?x=1', 'GET', ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $answer->getStatusCode());
        self::assertSame('BYPASS', $this->cacheOf($answer));
    }

    #[Test]
    public function anUnvouchedHostBypasses(): void
    {
        $client = $this->client();
        $this->vouch('a.test');
        $id = $this->publish('Для кеша', '/test-pc-host');
        self::assertSame([], Request::getTrustedHosts(), 'no trusted hosts: only the site address vouches');

        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://evil.test/test-pc-host')));
        $again = $this->visit($client, 'http://evil.test/test-pc-host');
        self::assertSame('BYPASS', $this->cacheOf($again));
        self::assertStringContainsString('Для кеша', (string) $again->getContent());
        self::assertFalse($this->stored($id, 'http://evil.test/test-pc-host'), 'nothing was stored for a host nobody vouched for');
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://evil.test:8080/test-pc-host')));

        self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://a.test/test-pc-host')));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://a.test/test-pc-host')));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://A.TEST/test-pc-host')), 'the case of the host does not matter');
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://a.test:8080/test-pc-host')), 'the port is part of the host');
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://b.a.test/test-pc-host')), 'a subdomain is another host');

        $this->vouch('');
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://a.test/test-pc-host')), 'without the setting nobody is vouched for');
        $this->vouch('https://a.test:8080/');
        self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://a.test:8080/test-pc-host')), 'the setting may carry a scheme and a port');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://a.test:8080/test-pc-host')));
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://a.test/test-pc-host')));
    }

    #[Test]
    public function trustedHostsVouchForAHost(): void
    {
        $client = $this->client();
        $client->catchExceptions(true);
        $this->vouch('');
        $this->publish('Для кеша', '/test-pc-trusted');
        $before = Request::getTrustedHosts();
        Request::setTrustedHosts(['^a\.test$', '^b\.test$']);

        try {
            self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://a.test/test-pc-trusted')), 'no site address, but Symfony vouches for the host');
            self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://a.test/test-pc-trusted')));
            self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://b.test/test-pc-trusted')), 'the host is part of the entry');
            self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://b.test/test-pc-trusted')));
            $body = (string) $this->visit($client, 'http://b.test/test-pc-trusted')->getContent();
            self::assertStringNotContainsString('rel="canonical"', $body, 'trusted hosts permit caching but do not configure an SEO origin');
            self::assertStringNotContainsString('http://b.test', $body);
            self::assertStringNotContainsString('http://a.test', $body);
            self::assertSame(400, $this->visit($client, 'http://evil.test/test-pc-trusted')->getStatusCode(), 'any other host is refused before the controller');
        } finally {
            Request::setTrustedHosts($before);
        }
    }

    #[Test]
    public function aPortOutsideTheDefaultBypasses(): void
    {
        $client = $this->client();
        $client->catchExceptions(true);
        $this->vouch('');
        $id = $this->publish('Для кеша', '/test-pc-port');
        $before = Request::getTrustedHosts();
        Request::setTrustedHosts(['^example\.com$']);

        try {
            self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://example.com:1111/test-pc-port')), 'Symfony lets the host through, and the port is anybody\'s');
            self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://example.com:1111/test-pc-port')));
            self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'https://example.com:1111/test-pc-port')));
            self::assertFalse($this->stored($id, 'http://example.com:1111/test-pc-port'), 'nothing was stored for a port nobody vouched for');
            self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://example.com/test-pc-port')));
            self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://example.com/test-pc-port')));
            self::assertSame('MISS', $this->cacheOf($this->visit($client, 'https://example.com:443/test-pc-port')), 'the default port of the scheme is the host as it is');
            self::assertSame('HIT', $this->cacheOf($this->visit($client, 'https://example.com/test-pc-port')));

            $this->vouch('example.com:1111');
            self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://example.com:1111/test-pc-port')), 'the port of the site address is the site\'s own');
            self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://example.com:1111/test-pc-port')));
            self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://example.com:2222/test-pc-port')));
        } finally {
            Request::setTrustedHosts($before);
        }
    }

    #[Test]
    public function aForwardedPortOutsideTheDefaultBypasses(): void
    {
        $client = $this->client();
        $this->vouch('');
        $this->publish('Для кеша', '/test-pc-fport');
        $hosts = Request::getTrustedHosts();
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        Request::setTrustedHosts(['^localhost$']);
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PORT);

        try {
            self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-fport', 'GET', ['HTTP_X_FORWARDED_PORT' => '9999'])), 'the proxy says a port, and the key would carry it');
            self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-fport')));
            self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-fport')));
            self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-fport', 'GET', ['HTTP_X_FORWARDED_PORT' => '80'])), 'the default port is the same entry');
        } finally {
            Request::setTrustedHosts($hosts);
            Request::setTrustedProxies($proxies, $headers);
        }
    }

    #[Test]
    public function aNonCanonicalPathBypasses(): void
    {
        $client = $this->client();
        $id = $this->publish('Для кеша', '/test-pc-canon');

        foreach (['/TEST-PC-CANON', '/%74est-pc-canon', '/test-pc-canon%09'] as $path) {
            $answer = $this->visit($client, 'http://localhost' . $path);
            self::assertSame(200, $answer->getStatusCode(), $path . ' is the page');
            self::assertSame('BYPASS', $this->cacheOf($answer), $path . ' is another spelling of the address');
            self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://localhost' . $path)));
        }
        self::assertFalse($this->stored($id, '/test-pc-canon'), 'no spelling stored anything');

        $first = $this->visit($client, '/test-pc-canon');
        self::assertSame('MISS', $this->cacheOf($first));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-canon')));
        self::assertStringContainsString('<link rel="canonical" href="https://localhost/test-pc-canon"/>', (string) $this->visit($client, '/test-pc-canon')->getContent(), 'what the shared entry says about the address is the address');
    }

    #[Test]
    public function aCyrillicAddressIsKeptUnderItsEncodedPath(): void
    {
        $client = $this->client();
        $path = Url::encodePath('/тест-пк-путь');
        $this->publish('Для кеша', '/тест-пк-путь');

        self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://localhost' . $path)));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://localhost' . $path)));
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://localhost' . \strtolower($path))), 'the same letters spelled with small hex digits are another path');
    }

    #[Test]
    public function theCanonicalAddressIsTheLiveUrlAsServed(): void
    {
        $this->vouch('localhost');
        $cache = $this->cache(new ArrayAdapter());
        $page = static function (string $url): PageTranslation {
            $t = new PageTranslation(new Page(), 'ru');
            $t->published = ['url' => $url];
            $t->publishedAt = new \DateTimeImmutable();

            return $t;
        };
        $bypasses = static fn (string $path, string $url): bool => $cache->bypasses(Request::create('http://localhost' . $path), $page($url));

        self::assertFalse($bypasses('/', '/'), 'the home page is the slash');
        self::assertTrue($bypasses('//', '/'));
        self::assertFalse($bypasses('/About', '/About'), 'the case is the one the page was saved with');
        self::assertTrue($bypasses('/about', '/About'));
        self::assertFalse($bypasses('/about/', 'about/'), 'a stored address with a trailing slash is served with it, and the leading slash is added');
        self::assertTrue($bypasses('/about', 'about/'), 'the other spelling redirects');
        self::assertFalse($bypasses('/about', 'about'));
        self::assertTrue($bypasses('/about/', 'about'));
    }

    #[Test]
    public function aForwardedPrefixBypasses(): void
    {
        $client = $this->client();
        $id = $this->publish('Для кеша', '/test-pc-prefix');
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_FORWARDED);

        try {
            foreach ([['HTTP_X_FORWARDED_PREFIX' => '/anything'], ['HTTP_FORWARDED' => 'for=1.2.3.4;proto=https']] as $server) {
                self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-prefix', 'GET', $server)), \array_key_first($server) . ' decides the base of the links, so it decides the page');
                self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-prefix', 'GET', $server)));
            }
            self::assertFalse($this->stored($id, '/test-pc-prefix'));
            self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-prefix')));
            self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-prefix')));
        } finally {
            Request::setTrustedProxies($proxies, $headers);
        }
    }

    #[Test]
    public function theBaseUrlIsPartOfTheKey(): void
    {
        $cache = $this->cache(new ArrayAdapter());
        $root = Request::create('https://a.test/x');
        $nested = Request::create('https://a.test/app/x', 'GET', [], [], [], ['SCRIPT_NAME' => '/app/index.php', 'SCRIPT_FILENAME' => '/var/www/app/index.php', 'PHP_SELF' => '/app/index.php/x']);
        self::assertSame('/app', $nested->getBaseUrl());
        $cache->put($root, 'fp', new Response('<p>root</p>'), null);

        self::assertNull($cache->get($nested, 'fp'), 'a page drawn under /app has links under /app');
        $cache->put($nested, 'fp', new Response('<p>nested</p>'), null);
        self::assertSame('<p>root</p>', $cache->get($root, 'fp')?->getContent());
        self::assertSame('<p>nested</p>', $cache->get($nested, 'fp')?->getContent());
    }

    #[Test]
    public function expiredEntriesAreRemovedFromThePoolOncePerInterval(): void
    {
        $clock = new MockClock('2026-11-03 12:00:00 UTC');
        $pool = new class(0, false, 0, 0, $clock) extends ArrayAdapter implements PruneableInterface {
            public function prune(): bool
            {
                return $this->clear('never-a-key-'); // a prefix makes the array adapter drop the expired entries
            }
        };
        $cache = $this->cache($pool, 3600, $clock);
        $entries = static fn (): array => \array_values(\array_filter(\array_keys($pool->getValues()), static fn (string $key): bool => 41 === \strlen($key)));
        $request = Request::create('https://a.test/x');

        $cache->put($request, 'short', new Response('<p>1</p>'), 60);
        [$short] = $entries();

        $clock->modify('+120 seconds');
        $cache->put($request, 'next', new Response('<p>2</p>'), null);
        self::assertContains($short, $entries(), 'the pool was cleaned a moment ago: not on every put');

        $clock->modify('+10 minutes');
        $cache->put($request, 'later', new Response('<p>3</p>'), null);
        self::assertNotContains($short, $entries(), 'a put after the interval removes what has expired');
        self::assertCount(2, $entries(), 'and keeps what has not');
        self::assertNotNull($cache->get($request, 'later'));
    }

    #[Test]
    public function aPoolThatCannotPruneIsLeftAlone(): void
    {
        $pool = new ArrayAdapter();
        $cache = $this->cache($pool);

        self::assertTrue($cache->put(Request::create('https://a.test/x'), 'fp', new Response('<p>1</p>'), null));
        self::assertCount(1, $pool->getValues(), 'no marker where there is nothing to prune');
        self::assertTrue($this->cache(new NullAdapter())->put(Request::create('https://a.test/x'), 'fp', new Response('<p>1</p>'), null));
    }

    #[Test]
    public function clearSaysWhenThePoolRefused(): void
    {
        $refusing = new class extends ArrayAdapter {
            public function clear(string $prefix = ''): bool
            {
                return false;
            }
        };

        self::assertFalse($this->cache($refusing)->clear());
        self::assertTrue($this->cache(new ArrayAdapter())->clear());
    }

    #[Test]
    public function theCommandFailsWhenThePoolRefused(): void
    {
        $this->client();
        $refusing = new class extends ArrayAdapter {
            public function clear(string $prefix = ''): bool
            {
                return false;
            }
        };
        $tester = new CommandTester(new ClearPageCacheCommand($this->cache($refusing)));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Не получилось сбросить кэш страниц', $tester->getDisplay());
        self::assertStringNotContainsString('ops.', $tester->getDisplay(), 'the text is translated');
    }

    #[Test]
    public function theHealthActionFailsWhenThePoolRefused(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->catchExceptions(true);
        $c = self::getContainer();
        $refusing = new class extends ArrayAdapter {
            public function clear(string $prefix = ''): bool
            {
                return false;
            }
        };
        $c->set(PageCache::class, $this->cache($refusing));
        $csrf = $this->signIn($client, $c->get(UserManager::class)->create('test-pc-admin@example.com', 'Админ', 'admin', 'password-for-test'));

        $this->post($client, $csrf);

        self::assertSame(500, $client->getResponse()->getStatusCode());
        self::assertSame(['error' => 'Не получилось сбросить кэш страниц'], \json_decode((string) $client->getResponse()->getContent(), true));
    }

    #[Test]
    public function aSiteAddressInCyrillicOrWithTheDefaultPortStillVouches(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-idn');

        foreach (['пример.рф', 'ПРИМЕР.РФ', 'https://пример.рф/', 'xn--e1afmkfd.xn--p1ai', 'https://xn--e1afmkfd.xn--p1ai:443'] as $domain) {
            $this->vouch($domain);
            self::assertSame('MISS', $this->cacheOf($this->visit($client, 'https://xn--e1afmkfd.xn--p1ai/test-pc-idn')), $domain . ': the browser asks for the host in ASCII');
            self::assertSame('HIT', $this->cacheOf($this->visit($client, 'https://xn--e1afmkfd.xn--p1ai/test-pc-idn')), $domain);
        }
        $this->vouch('https://example.com:443');
        self::assertSame('MISS', $this->cacheOf($this->visit($client, 'https://example.com/test-pc-idn')), 'the default port written out is no port');
        $this->vouch('http://example.com:80');
        self::assertSame('MISS', $this->cacheOf($this->visit($client, 'http://example.com/test-pc-idn')));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, 'http://example.com/test-pc-idn')));
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, 'http://xn--e1afmkfd.xn--p1ai/test-pc-idn')), 'another host: the setting is http://example.com');
    }

    #[Test]
    public function aRenderThatStartsASessionIsNotStored(): void
    {
        $client = $this->client();
        $id = $this->publish('Для кеша', '/test-pc-session');

        $first = $this->visit($client, '/test-pc-session');
        self::assertSame('MISS', $this->cacheOf($first));
        $client->getCookieJar()->clear();
        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-session')), 'the render that started a session was not kept');
        self::assertFalse($this->stored($id, '/test-pc-session'));
    }

    #[Test]
    public function headFirstDoesNotStoreAnEmptyPage(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-head');

        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-head', 'HEAD')));
        $get = $this->visit($client, '/test-pc-head');
        self::assertSame('MISS', $this->cacheOf($get), 'the answer to HEAD was not kept');
        self::assertStringContainsString('Для кеша', (string) $get->getContent());
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-head')));

        $head = $this->visit($client, '/test-pc-head', 'HEAD');
        self::assertSame('HIT', $this->cacheOf($head), 'HEAD uses the entry GET made');
        self::assertSame('', (string) $head->getContent());
    }

    #[Test]
    public function aSessionAuthorizationOrDebugBypass(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-bypass');
        $session = self::getContainer()->get('session.factory')->createSession()->getName();

        $withSession = $this->visit($client, '/test-pc-bypass', 'GET', [], [$session => 'abcdef']);
        self::assertSame('BYPASS', $this->cacheOf($withSession));
        self::assertStringContainsString('Для кеша', (string) $withSession->getContent(), 'drawn fresh');
        $client->getCookieJar()->clear();
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-bypass', 'GET', ['HTTP_AUTHORIZATION' => 'Bearer x'])));
        $plain = $this->visit($client, '/test-pc-bypass');
        self::assertSame('MISS', $this->cacheOf($plain), 'what they drew was not kept');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-bypass')));
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-bypass', 'GET', [], [$session => 'abcdef'])), 'a visitor with a session is not served from the cache even when there is an entry');

        self::ensureKernelShutdown();
        $client = $this->client(['debug' => true]);
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-bypass')), 'in development a changed template must show at once');
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-bypass')));

        self::ensureKernelShutdown();
        $client = $this->client(['itk' => ['page_cache' => ['enabled' => false]]]);
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-bypass')));
        self::assertSame('BYPASS', $this->cacheOf($this->visit($client, '/test-pc-bypass')));
    }

    #[Test]
    public function aMissingPageIsNotKept(): void
    {
        $client = $this->client();
        $client->catchExceptions(true);

        self::assertSame(404, $this->visit($client, '/test-pc-nothing-here')->getStatusCode());
        $again = $this->visit($client, '/test-pc-nothing-here');
        self::assertSame(404, $again->getStatusCode());
        self::assertSame('', $this->cacheOf($again));
    }

    #[Test]
    public function aThrowingTemplateKeepsNothing(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-boom');

        try {
            $this->visit($client, '/test-pc-boom');
            self::fail('the template breaks');
        } catch (\Throwable) {
        }
        self::ensureKernelShutdown();
        $client = $this->client();
        $client->catchExceptions(true);
        self::assertGreaterThanOrEqual(500, $this->visit($client, '/test-pc-boom')->getStatusCode());
        self::assertNotSame('HIT', $this->cacheOf($client->getResponse()));
    }

    #[Test]
    public function onlyAPlainAnswer200IsKept(): void
    {
        $cache = $this->cache(new ArrayAdapter());
        $get = Request::create('https://a.test/x');
        $page = static fn (int $status = 200): Response => new Response('<p>Страница</p>', $status);

        self::assertTrue($cache->put($get, 'fp', $page(), null));
        self::assertSame('<p>Страница</p>', $cache->get($get, 'fp')?->getContent());

        $cookie = $page();
        $cookie->headers->setCookie(Cookie::create('visitor', '1'));
        self::assertFalse($cache->put($get, 'cookie', $cookie, null), 'an answer with Set-Cookie is for one visitor');
        self::assertNull($cache->get($get, 'cookie'));

        self::assertFalse($cache->put($get, 'missing', $page(404), null));
        self::assertFalse($cache->put($get, 'redirect', $page(301), null));
        self::assertFalse($cache->put($get, 'forbidden', $page(), false));
        self::assertFalse($cache->put(Request::create('https://a.test/x', 'HEAD'), 'head', $page(), null), 'an answer to HEAD has no body');
        self::assertNull($cache->get($get, 'head'));

        $withSession = Request::create('https://a.test/x');
        $session = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $session->start();
        $withSession->setSession($session);
        self::assertFalse($cache->put($withSession, 'session', $page(), null), 'a started session may have put its token into the page');
    }

    #[Test]
    public function aGarbageValueInThePoolIsAMiss(): void
    {
        $pool = new ArrayAdapter();
        $cache = $this->cache($pool);
        $request = Request::create('https://a.test/x');
        $cache->put($request, 'fp', new Response('<p>1</p>'), null);
        $key = [] === $pool->getValues() ? '' : (string) \array_key_first($pool->getValues());
        self::assertNotSame('', $key);

        foreach ([['html' => '<p>2</p>'], 'text', ['html' => 1, 'status' => 200, 'contentType' => 'text/html', 'created' => 1]] as $garbage) {
            $pool->save($pool->getItem($key)->set($garbage));
            self::assertNull($cache->get($request, 'fp'), 'a value of another shape is not an entry');
        }
    }

    #[Test]
    public function theTtlOfTheConfigurationLimitsAnEntry(): void
    {
        $clock = new MockClock('2026-11-03 12:00:00 UTC');
        $cache = $this->cache(new ArrayAdapter(0, false, 0, 0, $clock), 60, $clock);
        $request = Request::create('https://a.test/x');
        $cache->put($request, 'fp', new Response('<p>1</p>'), null);

        $clock->modify('+59 seconds');
        self::assertNotNull($cache->get($request, 'fp'));
        $clock->modify('+2 seconds');
        self::assertNull($cache->get($request, 'fp'), 'the entry does not live longer than the ttl');
    }

    #[Test]
    public function theTtlOfTheOptionReachesTheCache(): void
    {
        $client = $this->client(['itk' => ['page_cache' => ['ttl' => 3]]]);
        $this->publish('Для кеша', '/test-pc-ttl');
        $this->visit($client, '/test-pc-ttl');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-ttl')), 'the entry lives at least two seconds: the second visit is well inside');

        \sleep(4);

        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-ttl')));
    }

    #[Test]
    public function a304StaysAsBefore(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-304');
        $etag = (string) $this->visit($client, '/test-pc-304')->headers->get('ETag');

        $answer = $this->visit($client, '/test-pc-304', 'GET', ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertSame(304, $answer->getStatusCode());
        self::assertSame('', (string) $answer->getContent());
        self::assertSame('HIT', $this->cacheOf($answer), 'nobody drew it');
        self::assertStringContainsString('no-cache', (string) $answer->headers->get('Cache-Control'));
    }

    /** @return iterable<string, array{int|false|null, int|false, int|false}> */
    public static function directives(): iterable
    {
        yield 'the first number stands' => [null, 300, 300];
        yield 'the smaller number wins' => [600, 120, 120];
        yield 'the smaller number wins, either way' => [120, 600, 120];
        yield 'false wins over a number' => [300, false, false];
        yield 'false stays' => [false, 300, false];
        yield 'false stays against another false' => [false, false, false];
        yield 'a number below one is false' => [null, 0, false];
        yield 'a negative number is false' => [300, -5, false];
    }

    #[Test]
    #[DataProvider('directives')]
    public function directivesAreMergedInOnePlace(int|false|null $current, int|false $asked, int|false $expected): void
    {
        self::assertSame($expected, RenderState::mergeCache($current, $asked));
    }

    #[Test]
    public function itkCacheFalseStoresNothing(): void
    {
        $client = $this->client();
        $off = $this->probe('off');
        $zero = $this->probe('0');
        $plain = $this->probe('none');

        self::assertSame('MISS', $this->cacheOf($this->visit($client, $off)));
        self::assertSame('MISS', $this->cacheOf($this->visit($client, $off)));
        self::assertSame('MISS', $this->cacheOf($this->visit($client, $zero)));
        self::assertSame('MISS', $this->cacheOf($this->visit($client, $zero)), 'a number below one is the same as false');
        self::assertSame('MISS', $this->cacheOf($this->visit($client, $plain)));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, $plain)), 'the page without the call is kept as usual');
    }

    #[Test]
    public function aDirectiveShortensTheEntryButNeverLengthensIt(): void
    {
        $clock = new MockClock('2026-11-03 12:00:00 UTC');
        $cache = $this->cache(new ArrayAdapter(0, false, 0, 0, $clock), 3600, $clock);
        $request = Request::create('https://a.test/x');
        $cache->put($request, 'short', new Response('<p>1</p>'), 300);
        $cache->put($request, 'plain', new Response('<p>2</p>'), null);
        $cache->put(Request::create('https://b.test/x'), 'long', new Response('<p>3</p>'), 7200);

        $clock->modify('+299 seconds');
        self::assertNotNull($cache->get($request, 'short'));
        $clock->modify('+2 seconds');
        self::assertNull($cache->get($request, 'short'), 'itk_cache(300) at the ttl of an hour: five minutes');
        self::assertNotNull($cache->get($request, 'plain'));
        $clock->modify('+3300 seconds');
        self::assertNull($cache->get($request, 'plain'));
        self::assertNull($cache->get(Request::create('https://b.test/x'), 'long'), 'a template cannot make the entry live longer than the ttl of the option');
    }

    #[Test]
    public function theDirectiveIsWhatTheTemplatesAsked(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $renderer = self::getContainer()->get(PageRenderer::class);
        $asked = function (string $url) use ($client, $renderer): int|false|null {
            $this->visit($client, $url);

            return $renderer->lastCacheDirective();
        };
        $plain = $this->probe('none');
        $layoutOnly = $this->probe('300');
        $layoutBigger = $this->probe('600', '120');
        $blockBigger = $this->probe('120', '600');
        $blockOff = $this->probe('600', 'off');

        self::assertNull($asked($plain));
        self::assertSame(300, $asked($layoutOnly));
        self::assertSame(120, $asked($layoutBigger), 'the layout asks 600, a block asks 120: the smallest');
        self::assertSame(120, $asked($blockBigger));
        self::assertFalse($asked($blockOff), 'false wins over a number');
        $this->publish('Для кеша', '/test-pc-directive-other');
        self::assertNull($asked('/test-pc-directive-other'), 'the next render does not inherit it');
    }

    #[Test]
    public function theSmallestDirectiveWins(): void
    {
        $client = $this->client();
        $number = $this->probe('600', '120');
        $off = $this->probe('600', 'off');

        self::assertSame('MISS', $this->cacheOf($this->visit($client, $number)));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, $number)));
        self::assertSame('MISS', $this->cacheOf($this->visit($client, $off)));
        self::assertSame('MISS', $this->cacheOf($this->visit($client, $off)), 'a number in the layout, false in a block: not kept');
    }

    #[Test]
    public function aDirectiveOfOneSecondExpiresTheEntry(): void
    {
        $client = $this->client();
        $short = $this->probe('1');
        $long = $this->probe('1000');
        $this->visit($client, $short);
        $this->visit($client, $long);
        self::assertSame('HIT', $this->cacheOf($this->visit($client, $long)));

        \sleep(2);

        self::assertSame('MISS', $this->cacheOf($this->visit($client, $short)), 'the entry of itk_cache(1) is gone');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, $long)), 'the control: a long directive is still there');
    }

    #[Test]
    public function itkCacheOutsideALiveRenderIsHarmless(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $this->publish('Для кеша', '/test-pc-outside');
        $forbidding = $this->publish('Для кеша', '/test-pc-probe-off-none');
        $c = self::getContainer();

        self::assertSame('ok', $c->get(Environment::class)->createTemplate('{% do itk_cache(false) %}ok{{ itk_cache(60) }}')->render([]), 'in a letter or a script the call changes nothing and prints nothing');
        $c->get(RequestStack::class)->push(Request::create('/admin/preview'));
        self::assertSame(200, $c->get(PageRenderer::class)->renderPreview($forbidding)->getStatusCode(), 'the preview draws the draft as always, even when the template forbids the cache');
        $c->get(RequestStack::class)->pop();
        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-outside')));
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-outside')), 'what was asked outside a render did not follow into the next page');
    }

    #[Test]
    public function clearMakesTheNextVisitAMiss(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-clear');
        $this->publish('Соседняя', '/test-pc-clear-other');
        $this->visit($client, '/test-pc-clear');
        $this->visit($client, '/test-pc-clear-other');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-clear')));

        self::getContainer()->get(PageCache::class)->clear();

        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-clear')));
        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-clear-other')), 'every page, not only one');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-clear')));
    }

    #[Test]
    public function clearLeavesTheOtherCachesAlone(): void
    {
        $client = $this->client();
        $pool = self::getContainer()->get('cache.app');
        $pool->save($pool->getItem('test_pc_other')->set('kept'));
        $this->publish('Для кеша', '/test-pc-apart');
        $this->visit($client, '/test-pc-apart');

        self::getContainer()->get(PageCache::class)->clear();

        self::assertTrue($pool->getItem('test_pc_other')->isHit(), 'the pool of the pages is apart from cache.app');
        $pool->deleteItem('test_pc_other');
    }

    #[Test]
    public function theCommandClears(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-pc-command');
        $this->visit($client, '/test-pc-command');
        self::assertSame('HIT', $this->cacheOf($this->visit($client, '/test-pc-command')));

        $tester = new CommandTester((new Application(self::$kernel))->find('itk:cache:clear'));
        $code = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('Кэш страниц сброшен', $tester->getDisplay());
        self::assertSame('Сбросить кэш страниц сайта', (new Application(self::$kernel))->find('itk:cache:clear')->getDescription());
        self::assertSame('MISS', $this->cacheOf($this->visit($client, '/test-pc-command')));
    }

    #[Test]
    public function theHealthActionClears(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $client->catchExceptions(true);
        $this->publish('Для кеша', '/test-pc-health');
        $this->visit($client, '/test-pc-health');
        $visitor = function () use ($client): string {
            $cookies = $client->getCookieJar()->all();
            $client->getCookieJar()->clear();
            $cache = $this->cacheOf($this->visit($client, '/test-pc-health'));
            foreach ($cookies as $cookie) {
                $client->getCookieJar()->set($cookie);
            }

            return $cache;
        };
        self::assertSame('HIT', $visitor());

        $users = self::getContainer()->get(UserManager::class);
        $csrf = $this->signIn($client, $users->create('test-pc-editor@example.com', 'Редактор', 'editor', 'password-for-test'));
        $this->post($client, $csrf);
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'no right «Разработчику → Править»');
        self::assertSame('HIT', $visitor(), 'nothing was cleared');

        $csrf = $this->signIn($client, $users->create('test-pc-admin@example.com', 'Админ', 'admin', 'password-for-test'));
        $this->post($client, 'wrong');
        self::assertSame(403, $client->getResponse()->getStatusCode(), 'a call without the token of the session is refused like the other actions');
        self::assertSame('HIT', $visitor());

        $this->post($client, $csrf);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['ok' => true], \json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame('MISS', $visitor());
    }

    private function signIn(KernelBrowser $client, User $user): string
    {
        $client->getCookieJar()->clear();
        $client->loginUser($user, 'itk_admin');
        $client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $client->getResponse()->getContent(), $m);

        return $m[1] ?? '';
    }

    private function post(KernelBrowser $client, string $csrf): void
    {
        $client->request('POST', '/admin/api/health/page-cache/clear', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], '{}');
    }
}

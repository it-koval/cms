<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Analytics\AnalyticsAsset;
use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageValidators;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Security\CspNonce;
use Itk\Cms\Tests\App\QueryCounter;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use Itk\Cms\YandexWebmaster\Model\WebmasterConnection;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

/** Живая страница отвечает ETag и Last-Modified; посетитель без сессии получает 304, пока не изменилось то, от чего страница зависит. */
final class ConditionalResponseTest extends WebTestCase
{
    use TemporarySettings;

    /** @var list<string> */
    private array $created = [];
    /** @var list<string> */
    private array $items = [];
    /** @var list<string> */
    private array $files = [];

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
        foreach ($this->files as $id) {
            $c->get(Connection::class)->executeStatement('DELETE FROM itk_media_file WHERE id = ?', [$id]);
        }
        $this->restoreSettings();
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->catchExceptions(false);

        return $client;
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
     * @param array<string, mixed> $headers
     * @param array<array-key, mixed> $cookies
     *
     * @return array{0: int, 1: string, 2: string} status, etag, body
     */
    private function get(KernelBrowser $client, string $url, array $headers = [], array $cookies = []): array
    {
        foreach ($cookies as $name => $value) {
            $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($name, $value));
        }
        $client->request('GET', $url, [], [], $headers);
        $r = $client->getResponse();

        return [$r->getStatusCode(), (string) $r->headers->get('ETag'), (string) $r->getContent()];
    }

    #[Test]
    public function aVisitorWhoHasThePageGetsA304WithoutARender(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-cond');

        [$status, $etag, $body] = $this->get($client, '/test-cond');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^"[a-f0-9]{24}"$/', $etag);
        self::assertStringContainsString('Для кеша', $body);
        self::assertNotSame('', (string) $client->getResponse()->headers->get('Last-Modified'));
        self::assertStringContainsString('no-cache', (string) $client->getResponse()->headers->get('Cache-Control'), 'the browser asks again each time, the answer is the short 304');

        $queries = QueryCounter::during(function () use ($client, $etag, &$status304): void {
            $status304 = $this->get($client, '/test-cond', ['HTTP_IF_NONE_MATCH' => $etag]);
        });
        self::assertSame(304, $status304[0]);
        self::assertSame('', $status304[2]);
        self::assertStringContainsString('no-cache', (string) $client->getResponse()->headers->get('Cache-Control'), 'the 304 renews the headers of the browser: the rule to ask again must stay');
        self::assertSame($etag, $status304[1]);
        self::assertLessThanOrEqual(5, \count($queries), 'validators and the site address only: ' . \implode("\n", $queries));
    }

    #[Test]
    public function aOtherEtagOrOnlyADateIsAFullAnswer(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-cond-2');
        [, $etag] = $this->get($client, '/test-cond-2');

        self::assertSame(200, $this->get($client, '/test-cond-2', ['HTTP_IF_NONE_MATCH' => '"0123456789abcdef01234567"'])[0]);
        $date = (string) $client->getResponse()->headers->get('Last-Modified');
        self::assertSame(200, $this->get($client, '/test-cond-2', ['HTTP_IF_MODIFIED_SINCE' => $date])[0], 'a date alone cannot tell that a setting or a menu changed');
        self::assertSame(304, $this->get($client, '/test-cond-2', ['HTTP_IF_NONE_MATCH' => $etag])[0]);
    }

    #[Test]
    public function whatThePageDependsOnChangesTheEtag(): void
    {
        $client = $this->client();
        $c = self::getContainer();
        $id = $this->publish('Для кеша', '/test-cond-3');
        $etag = fn (): string => $this->get($client, '/test-cond-3')[1];
        $was = $etag();
        self::assertSame($was, $etag(), 'nothing changed: the same tag');
        /** @var \Closure(string): void $changed */
        $changed = static function (string $what) use (&$was, $etag): void {
            $now = $etag();
            self::assertNotSame($was, $now, $what . ' must make a new tag');
            $was = $now;
        };

        $c->get(PageService::class)->update($id, 'ru', ['title' => 'Для кеша, правка']);
        self::assertSame($was, $etag(), 'a draft is not on the site');

        $c->get(PageService::class)->publish($id);
        $changed('publishing the page again, even in the same second');

        $this->setting('requestButtonText', 'Позвоните мне');
        $changed('a setting');

        $c->get(MenuService::class)->syncSchemaAreas();
        $c->get(MenuService::class)->saveMenus(['header' => [['label' => 'Пункт', 'link' => ['kind' => 'url', 'url' => '/x']]]]);
        $changed('a menu');

        $other = $this->publish('Соседняя', '/test-cond-3-other');
        $changed('another page that goes live');
        $c->get(PageService::class)->unpublish($other);
        $changed('another page that goes off');

        $c->get(PageService::class)->publish($other);
        $c->get(PageService::class)->reorder([$id, $other]);
        $was = $etag();
        $c->get(PageService::class)->reorder([$other, $id]);
        $changed('the order of the live pages in the tree (a list shows pages by it)');

        $file = new MediaFile('/uploads/media/test-cond.jpg', 'test-cond.jpg', 'image/jpeg');
        $this->files[] = $file->id;
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($file);
        $em->flush();
        self::assertSame($was, $etag(), 'a picture without frames changes nothing on the pages');
        $c->get(Connection::class)->executeStatement('UPDATE itk_media_file SET crops = ? WHERE id = ?', ['{"16:9": {"x": 0, "y": 0, "w": 1, "h": 0.5}}', $file->id]); // the request has reset the manager
        $changed('a saved frame of a picture (it decides the address of the picture)');

        $item = $c->get(CollectionService::class)->create('tours', ['title' => 'Тур для кеша']);
        $this->items[] = $item['id'];
        $c->get(CollectionService::class)->publish('tours', $item['id']);
        $changed('a record of a collection');

        $c->get(MenuService::class)->saveMenus(['header' => []]);
    }

    #[Test]
    public function aDraftLeavesThePublicTagAlone(): void
    {
        $client = $this->client();
        $c = self::getContainer();
        $this->publish('Для черновиков', '/test-cond-drafts');
        $item = $c->get(CollectionService::class)->create('tours', ['title' => 'Тур для черновиков']);
        $this->items[] = $item['id'];
        $c->get(CollectionService::class)->publish('tours', $item['id']);
        $tag = $this->get($client, '/test-cond-drafts')[1];

        $c->get(CollectionService::class)->update('tours', $item['id'], ['city' => 'Город, правка']);
        self::assertSame($tag, $this->get($client, '/test-cond-drafts')[1], 'an autosave of a record is not on the site');

        $draft = $c->get(CollectionService::class)->create('tours', ['title' => 'Тур, ещё черновик']);
        $this->items[] = $draft['id'];
        self::assertSame($tag, $this->get($client, '/test-cond-drafts')[1], 'a new record that is not published');

        $page = $c->get(PageService::class)->create(['template' => 'default', 'title' => 'Ещё черновик', 'url' => '/test-cond-drafts-new']);
        $this->created[] = $page['id'];
        self::assertSame($tag, $this->get($client, '/test-cond-drafts')[1], 'a new page that is not published');

        $c->get(CollectionService::class)->publish('tours', $item['id']);
        self::assertNotSame($tag, $this->get($client, '/test-cond-drafts')[1], 'the same edit once it is published: the control');
    }

    #[Test]
    public function theQueryOfTheRequestIsPartOfTheTag(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-cond-4');

        self::assertNotSame($this->get($client, '/test-cond-4')[1], $this->get($client, '/test-cond-4?utm=1')[1]);
    }

    #[Test]
    public function aSignedInOrSessionVisitorGetsNoValidators(): void
    {
        $client = $this->client();
        $this->publish('Для кеша', '/test-cond-5');

        [$status, $etag] = $this->get($client, '/test-cond-5', [], [self::getContainer()->get('session.factory')->createSession()->getName() => 'abcdef']);

        self::assertSame(200, $status);
        self::assertSame('', $etag);
        self::assertFalse($client->getResponse()->headers->has('Last-Modified'));
    }

    #[Test]
    public function inDebugAndWithTheOptionOffThereAreNoValidators(): void
    {
        $client = $this->client(['debug' => true]);
        $this->publish('Для кеша', '/test-cond-6');
        self::assertSame('', $this->get($client, '/test-cond-6')[1], 'in development a changed template must show at once');
        self::ensureKernelShutdown();

        $client = $this->client(['itk' => ['conditional_responses' => false]]);
        self::assertSame('', $this->get($client, '/test-cond-6')[1]);
    }

    #[Test]
    public function theEtagIsTheStartOfTheFingerprint(): void
    {
        $c = self::getContainer();
        $id = $this->publish('Для кеша', '/test-cond-8');
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $validators = $c->get(PageValidators::class);
        $request = Request::create('/test-cond-8');

        $fingerprint = $validators->fingerprint($t, $request);

        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string) $fingerprint);
        self::assertSame('"' . \substr((string) $fingerprint, 0, 24) . '"', $validators->forPage($t, $request)['etag'] ?? null);
    }

    #[Test]
    public function analyticsModeAndAssetVersionChangeTheFingerprint(): void
    {
        $c = self::getContainer();
        $id = $this->publish('Аналитика', '/test-cond-analytics-asset');
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $request = Request::create('/test-cond-analytics-asset');
        $clock = new MockClock('2026-11-24 08:00:00 UTC');
        $fingerprint = static function (string $mode, AnalyticsAsset $asset) use ($c, $clock, $t, $request): ?string {
            $validators = new PageValidators(
                $c->get(Connection::class),
                true,
                false,
                $c->getParameter('kernel.cache_dir'),
                $c->getParameter('kernel.container_class'),
                $clock,
                $c->get(Stylesheets::class),
                analyticsStart: $mode,
                analyticsAsset: $asset,
            );

            return $validators->fingerprint($t, $request);
        };
        $current = new AnalyticsAsset();
        $differentFile = new AnalyticsAsset(file: __DIR__ . '/../../public/preview.js');
        $auto = $fingerprint(AnalyticsConfig::AUTO, $current);

        self::assertNotNull($auto);
        self::assertSame($auto, $fingerprint(AnalyticsConfig::AUTO, new AnalyticsAsset()));
        self::assertNotSame($auto, $fingerprint(AnalyticsConfig::MANUAL, $current));
        self::assertNotSame($auto, $fingerprint(AnalyticsConfig::AUTO, $differentFile));
    }

    #[Test]
    public function anEtagFromAutoGetsFreshManualHtml(): void
    {
        $auto = $this->client();
        $url = '/test-cond-analytics-mode';
        $this->publish('Аналитика', $url);
        $this->setting(AnalyticsConfig::METRIKA_ID, '12345678');

        [$status, $etag, $html] = $this->get($auto, $url);
        self::assertSame(200, $status);
        self::assertNotSame('', $etag);
        self::assertStringContainsString('data-start="auto"', $html);

        self::ensureKernelShutdown();
        $manual = $this->client(['itk' => ['analytics' => ['start' => 'manual']]]);
        [$status, $manualEtag, $html] = $this->get($manual, $url, ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(200, $status);
        self::assertNotSame($etag, $manualEtag);
        self::assertStringContainsString('data-start="manual"', $html);
        self::assertSame(304, $this->get($manual, $url, ['HTTP_IF_NONE_MATCH' => $manualEtag])[0]);
    }

    #[Test]
    public function aRequestNonceBypassesTheCachedPageAndConditionalResponse(): void
    {
        $client = $this->client();
        $url = '/test-cond-analytics-nonce';
        $this->publish('Аналитика с CSP', $url);
        $this->setting('domain', 'localhost');
        $this->setting(AnalyticsConfig::GA_ID, 'G-ABCD1234');
        $cache = self::getContainer()->get(PageCache::class);

        try {
            [, $etag] = $this->get($client, $url);
            self::assertNotSame('', $etag);
            $this->get($client, $url);
            self::assertSame('HIT', $client->getResponse()->headers->get('X-ITK-Cache'));

            $request = Request::create('http://localhost' . $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            $request->attributes->set(CspNonce::ATTRIBUTE, 'test-response-nonce');
            $kernel = self::$kernel;
            self::assertNotNull($kernel);
            $response = $kernel->handle($request, catch: false);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('BYPASS', $response->headers->get('X-ITK-Cache'));
            self::assertFalse($response->headers->has('ETag'));
            self::assertStringContainsString('nonce="test-response-nonce"', (string) $response->getContent());
            [, , $cached] = $this->get($client, $url);
            self::assertSame('HIT', $client->getResponse()->headers->get('X-ITK-Cache'));
            self::assertStringNotContainsString('test-response-nonce', $cached);
        } finally {
            $cache->clear();
        }
    }

    #[Test]
    public function counterIdsAndVerificationCodesInvalidateCachedHtml(): void
    {
        $client = $this->client();
        $url = '/test-cond-analytics-settings';
        $this->publish('Аналитика', $url);
        $this->setting('domain', 'localhost');
        $container = self::getContainer();
        $settings = $container->get(SettingsService::class);
        $webmaster = $container->get(WebmasterStore::class);
        $connectionWasSet = $settings->isSet(WebmasterStore::CONNECTION);
        $connectionBefore = $settings->storedNow(WebmasterStore::CONNECTION);
        $values = [
            AnalyticsConfig::METRIKA_ID => ['12345678', 'data-metrika="12345678"'],
            AnalyticsConfig::GA_ID => ['G-ABCD1234', 'data-ga="G-ABCD1234"'],
            AnalyticsConfig::YANDEX_VERIFICATION => ['abcdef1234567890', 'name="yandex-verification" content="abcdef1234567890"'],
            AnalyticsConfig::GOOGLE_VERIFICATION => ['ghijkl1234567890', 'name="google-site-verification" content="ghijkl1234567890"'],
        ];
        try {
            [, $etag] = $this->get($client, $url);
            self::assertNotSame('', $etag);
            $changed = function (string $markup) use ($client, $url, &$etag): void {
                [$status, $now, $html] = $this->get($client, $url, ['HTTP_IF_NONE_MATCH' => $etag]);
                self::assertSame(200, $status);
                self::assertNotSame($etag, $now);
                self::assertStringContainsString($markup, $html);
                $etag = $now;
            };

            foreach ($values as $key => [$value, $markup]) {
                $this->setting($key, $value);
                $changed($markup);
            }
            $webmaster->saveConnection(new WebmasterConnection('fixture-token', new \DateTimeImmutable(), 1, 'https:localhost:443', 'https://localhost', '7890abcdef123456', false));
            $changed('name="yandex-verification" content="7890abcdef123456"');

            [$status, $cachedEtag, $html] = $this->get($client, $url);
            self::assertSame(200, $status);
            self::assertSame($etag, $cachedEtag);
            self::assertSame('HIT', $client->getResponse()->headers->get('X-ITK-Cache'));
            self::assertSame(1, \substr_count($html, 'data-itk-analytics'));
            self::assertSame([304, $etag, ''], $this->get($client, $url, ['HTTP_IF_NONE_MATCH' => $etag]));
        } finally {
            if ($connectionWasSet) {
                $settings->set(WebmasterStore::CONNECTION, $connectionBefore);
            } else {
                $settings->remove(WebmasterStore::CONNECTION);
            }
            $container->get(PageCache::class)->clear();
        }
    }

    #[Test]
    public function aGivenFingerprintSavesTheQuery(): void
    {
        $c = self::getContainer();
        $id = $this->publish('Для кеша', '/test-cond-9');
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $validators = $c->get(PageValidators::class);
        $request = Request::create('/test-cond-9');
        $fingerprint = $validators->fingerprint($t, $request);
        self::assertNotNull($fingerprint);
        $alone = $validators->forPage($t, $request);

        $queries = QueryCounter::during(static function () use ($validators, $t, $request, $fingerprint, &$given): void {
            $given = $validators->forPage($t, $request, $fingerprint);
        });

        self::assertSame([], $queries, \implode("\n", $queries));
        self::assertEquals($alone, $given, 'the same tag and the same date as when the validators ask the database themselves');
    }

    #[Test]
    public function theFingerprintIgnoresSessionAndDebugRules(): void
    {
        $c = self::getContainer();
        $id = $this->publish('Для кеша', '/test-cond-10');
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $validators = $c->get(PageValidators::class);
        $request = Request::create('/test-cond-10', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer x']);

        self::assertNull($validators->forPage($t, $request), 'a request with Authorization gets no validators');
        self::assertIsString($validators->fingerprint($t, $request), 'the cache decides by its own rules');
    }

    #[Test]
    public function theTagIsNotOlderThanADay(): void
    {
        $c = self::getContainer();
        $id = $this->publish('Для кеша', '/test-cond-7');
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $tag = static function (string $now) use ($c, $t): string {
            $validators = new PageValidators($c->get(Connection::class), true, false, $c->getParameter('kernel.cache_dir'), $c->getParameter('kernel.container_class'), new MockClock($now), $c->get(Stylesheets::class));

            return (string) $validators->forPage($t, Request::create('/test-cond-7'))['etag'];
        };

        self::assertSame($tag('2026-11-01 08:00:00 UTC'), $tag('2026-11-01 23:59:59 UTC'), 'the same day: the same tag');
        self::assertNotSame($tag('2026-11-01 23:59:59 UTC'), $tag('2026-11-02 00:00:01 UTC'), 'a page that shows what is on now (the tours that have not started) must not stay in a cache for more than a day');
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/** Счётчики и коды подтверждения попадают на живые страницы сайта и больше никуда: ни в предпросмотр, ни в админку, ни в API, ни в не-HTML. */
final class AnalyticsInjectionTest extends WebTestCase
{
    use TemporarySettings;

    private const YANDEX_CODE = 'ya-code_Q7z9Kp2m';
    private const GOOGLE_CODE = 'AbCdEfGh-1234_xyz';

    /** @var list<string> */
    private array $created = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', $options['debug'] ?? false, framework: $options['framework'] ?? [], itk: $options['itk'] ?? []);
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
        $this->restoreSettings();
        $c->get(PageCache::class)->clear();
        $c->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.email LIKE :e')->setParameter('e', 'test-an-%@example.com')->execute();
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->catchExceptions(false);
        $this->setting('domain', 'localhost'); // the page cache keeps pages only for the host the site vouches for
        self::getContainer()->get(PageCache::class)->clear();

        return $client;
    }

    /** @param array<string, mixed> $values */
    private function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->setting($key, $value);
        }
    }

    private function publish(string $url): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Аналитика', 'url' => $url]);
        $this->created[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    /** The page as the renderer draws it, before any listener touched it. */
    private function drawn(string $id, string $url): string
    {
        $c = self::getContainer();
        $t = $c->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);
        $requests = $c->get(RequestStack::class);
        $requests->push(Request::create('http://localhost' . $url));
        try {
            return (string) $c->get(PageRenderer::class)->renderLive($t)->getContent();
        } finally {
            $requests->pop();
        }
    }

    private function body(KernelBrowser $client, string $url, string $method = 'GET'): string
    {
        $client->request($method, $url);

        return (string) $client->getResponse()->getContent();
    }

    private static function assertLoader(string $html, string $metrika = '', string $ga = '', string $start = 'auto', bool $webvisor = false): void
    {
        $loader = (new Crawler($html))->filter('script[data-itk-analytics]');
        self::assertCount(1, $loader, 'one loader for all configured counters');
        self::assertSame('', $loader->attr('defer'));
        self::assertSame($start, $loader->attr('data-start'));
        self::assertSame($metrika, $loader->attr('data-metrika'));
        self::assertSame($ga, $loader->attr('data-ga'));
        self::assertSame($webvisor ? '1' : '0', $loader->attr('data-webvisor'));
        self::assertSame('', $loader->text(), 'the loader contains no executable inline code');
        self::assertNotSame('', $loader->attr('src'));
    }

    private function signIn(KernelBrowser $client, string $name): void
    {
        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-an-' . $name . '@example.com', 'Аналитика', 'admin', 'password-for-test'), 'itk_admin');
    }

    /** Runs the response through the listeners of the real container, the way the kernel does for the main request of a route. */
    private function filter(Response $response, string $route = 'itk_page', int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $request = Request::create('/somewhere');
        $request->attributes->set('_route', $route);
        $kernel = self::getContainer()->get('http_kernel');
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ResponseEvent($kernel, $request, $type, $response), KernelEvents::RESPONSE);

        return $response;
    }

    #[Test]
    public function aLivePageGetsMetrikaBeforeTheHeadClosesAndTheNoscriptAfterTheBodyOpens(): void
    {
        $client = $this->client();
        $this->publish('/test-an-metrika');
        $this->configure(['yandexMetrikaId' => '12345678']);

        $html = $this->body($client, '/test-an-metrika');

        self::assertResponseIsSuccessful();
        self::assertLoader($html, '12345678');
        self::assertStringNotContainsString('metrika/tag.js', $html);
        self::assertStringNotContainsString('ym(', $html);
        self::assertLessThan(\strpos($html, '</head>'), \strpos($html, 'data-itk-analytics'), 'the script is inside the head');
        self::assertGreaterThan(\strpos($html, '<title>'), \strpos($html, 'data-itk-analytics'), 'after the tags of the layout');
        self::assertMatchesRegularExpression('~<body>\s*<noscript><div><img src="https://mc\.yandex\.ru/watch/12345678" style="position:absolute; left:-9999px;" alt=""></div></noscript>\s*<main>~', $html);
        self::assertSame(1, \substr_count($html, 'mc.yandex.ru/watch/12345678'));
    }

    #[Test]
    public function webvisorIsOnlyThereWhenTheToggleIs(): void
    {
        $client = $this->client();
        $this->publish('/test-an-wv');
        $this->configure(['yandexMetrikaId' => '12345678', 'yandexMetrikaWebvisor' => true]);

        self::assertLoader($this->body($client, '/test-an-wv'), '12345678', webvisor: true);

        $this->configure(['yandexMetrikaWebvisor' => false]);
        $html = $this->body($client, '/test-an-wv');
        self::assertLoader($html, '12345678', webvisor: false);
    }

    #[Test]
    public function theGoogleAnalyticsScriptAppears(): void
    {
        $client = $this->client();
        $this->publish('/test-an-ga');
        $this->configure(['googleAnalyticsId' => 'g-abc123xyz9']);

        $html = $this->body($client, '/test-an-ga');

        self::assertLoader($html, ga: 'G-ABC123XYZ9');
        self::assertStringNotContainsString('googletagmanager.com', $html);
        self::assertStringNotContainsString('gtag(', $html);
        self::assertLessThan(\strpos($html, '</head>'), \strpos($html, 'data-itk-analytics'));
        self::assertStringNotContainsString('mc.yandex.ru', $html, 'only the counter that is set');
        self::assertStringNotContainsString('<noscript>', $html);
    }

    #[Test]
    public function bothVerificationMetasAppearAlsoFromAPastedMetaTag(): void
    {
        $client = $this->client();
        $this->publish('/test-an-verify');
        $this->configure(['yandexVerification' => self::YANDEX_CODE, 'googleVerification' => '<meta name="google-site-verification" content="' . self::GOOGLE_CODE . '" />']);

        $html = $this->body($client, '/test-an-verify');

        self::assertSame(1, \substr_count($html, '<meta name="yandex-verification" content="' . self::YANDEX_CODE . '">'));
        self::assertSame(1, \substr_count($html, '<meta name="google-site-verification" content="' . self::GOOGLE_CODE . '">'));
        self::assertLessThan(\strpos($html, '</head>'), \strpos($html, 'yandex-verification'));
        self::assertStringNotContainsString('<script', $html, 'no script for the metas alone');
        self::assertStringNotContainsString('tag.js', $html);
        self::assertStringNotContainsString('gtag', $html);
    }

    #[Test]
    public function emptySettingsLeaveThePageByteIdentical(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-an-empty');
        $drawn = $this->drawn($id, '/test-an-empty');

        self::assertSame($drawn, $this->body($client, '/test-an-empty'), 'nothing set');

        $this->configure(['yandexMetrikaId' => '', 'yandexMetrikaWebvisor' => true, 'googleAnalyticsId' => '  ', 'yandexVerification' => '', 'googleVerification' => '']);
        self::assertSame($drawn, $this->body($client, '/test-an-empty'), 'set and empty (a toggle alone has no counter to record)');
    }

    #[Test]
    public function anInvalidIdOutputsNothing(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-an-invalid');
        $drawn = $this->drawn($id, '/test-an-invalid');
        $this->configure(['yandexMetrikaId' => '12ab', 'googleAnalyticsId' => 'UA-12345-1', 'yandexVerification' => 'short', 'googleVerification' => 'bad value"><script>']);

        self::assertSame($drawn, $this->body($client, '/test-an-invalid'));
    }

    #[Test]
    public function aValidValueIsPutOnTheSiteEvenWhenAnotherIsInvalid(): void
    {
        $client = $this->client();
        $this->publish('/test-an-mixed');
        $this->configure(['yandexMetrikaId' => 'nope', 'googleAnalyticsId' => 'G-ABCD1234']);

        $html = $this->body($client, '/test-an-mixed');

        self::assertStringContainsString('data-ga="G-ABCD1234"', $html);
        self::assertStringNotContainsString('mc.yandex.ru', $html);
    }

    #[Test]
    public function previewTheAdminTheApiAndTheSitemapAreUntouched(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-an-untouched');
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234', 'yandexVerification' => self::YANDEX_CODE, 'googleVerification' => self::GOOGLE_CODE]);
        $this->signIn($client, 'untouched');

        $client->request('GET', '/admin/preview/' . $id);
        self::assertResponseIsSuccessful();
        $preview = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('</head>', $preview, 'the preview is a whole page, so the check below means something');
        self::assertStringContainsString('<body', $preview);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $shell = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('</head>', $shell);

        $client->request('GET', '/admin/api/settings');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        $api = (string) $client->getResponse()->getContent();
        self::assertSame('12345678', \json_decode($api, true)['yandexMetrikaId'], 'the API shows the value as a value and nothing else');

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $sitemap = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<urlset', $sitemap);

        foreach (['data-itk-analytics', 'mc.yandex.ru', 'googletagmanager.com', 'yandex-verification', 'google-site-verification', 'mc.yandex.ru/watch'] as $mark) {
            self::assertStringNotContainsString($mark, $preview, 'preview ' . $mark);
            self::assertStringNotContainsString($mark, $shell, 'shell ' . $mark);
            self::assertStringNotContainsString($mark, $sitemap, 'sitemap ' . $mark);
        }
        self::assertStringNotContainsString('mc.yandex.ru', $api);
        self::assertSame(\substr_count($api, '12345678'), 1, 'one copy of the value, the one in the JSON');

        $client->request('GET', '/test-an-untouched');
        self::assertLoader((string) $client->getResponse()->getContent(), '12345678', 'G-ABCD1234');
    }

    /** @return iterable<string, array{string}> */
    public static function otherRoutes(): iterable
    {
        yield 'admin' => ['itk_admin_app'];
        yield 'admin preview' => ['itk_admin_preview'];
        yield 'api' => ['itk_api_settings_get'];
        yield 'mcp' => ['itk_mcp'];
        yield 'profiler' => ['_profiler'];
        yield 'toolbar' => ['_wdt'];
        yield 'no route' => [''];
    }

    #[Test]
    #[DataProvider('otherRoutes')]
    public function htmlOnTheRoutesOfTheAdminTheApiAndTheToolbarIsLeftAlone(string $route): void
    {
        $this->client();
        $this->configure(['yandexMetrikaId' => '12345678']);
        $html = '<html><head></head><body><p>x</p></body></html>';

        $response = $this->filter(new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']), $route);

        self::assertSame($html, $response->getContent());
    }

    #[Test]
    public function aRouteOfTheSitesOwnControllerGetsThem(): void
    {
        $client = $this->client();
        $this->configure(['yandexMetrikaId' => '12345678']);

        $html = $this->body($client, '/test-analytics/controller');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Site controller fixture', $html);
        self::assertLoader($html, '12345678');
    }

    #[Test]
    public function manualModeExposesBothIdsWithoutAnyVendorMarkupOrNoscript(): void
    {
        $client = $this->client(['itk' => ['analytics' => ['start' => 'manual']]]);
        $this->configure(['yandexMetrikaId' => '12345678', 'yandexMetrikaWebvisor' => true, 'googleAnalyticsId' => 'G-ABCD1234']);

        $html = $this->body($client, '/test-analytics/controller');

        self::assertResponseIsSuccessful();
        self::assertLoader($html, '12345678', 'G-ABCD1234', 'manual', true);
        foreach (['mc.yandex.ru', 'googletagmanager.com', '<noscript', 'ym(', 'gtag('] as $mark) {
            self::assertStringNotContainsString($mark, $html);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function legacyEmbeds(): iterable
    {
        yield 'metrika already embedded' => ['metrika', '', 'G-ABCD1234'];
        yield 'ga already embedded' => ['ga', '12345678', ''];
        yield 'both already embedded' => ['both', '', ''];
    }

    #[Test]
    #[DataProvider('legacyEmbeds')]
    public function autoOnlyPassesCountersWhichTheLegacySiteHasNotAlreadyEmbedded(string $embed, string $metrika, string $ga): void
    {
        $client = $this->client();
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234']);

        $html = $this->body($client, '/test-analytics/controller/' . $embed);

        self::assertResponseIsSuccessful();
        self::assertLoader($html, $metrika, $ga);
        self::assertSame(1, \substr_count($html, 'mc.yandex.ru/watch/12345678'), 'the kernel only adds a pixel when it runs Metrika itself');
        self::assertSame('' === $metrika ? 1 : 0, \substr_count($html, 'ym(12345678, "init", {clickmap:true});'), 'the existing inline Metrika remains unchanged');
        self::assertSame('' === $ga ? 1 : 0, \substr_count($html, "gtag('config','G-ABCD1234');"), 'the existing inline GA remains unchanged');
    }

    #[Test]
    public function manualLeavesLegacySiteScriptsAndPixelIntactAndKeepsTheirIdsAvailable(): void
    {
        $client = $this->client(['itk' => ['analytics' => ['start' => 'manual']]]);
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234']);

        $html = $this->body($client, '/test-analytics/controller/both');

        self::assertResponseIsSuccessful();
        self::assertLoader($html, '12345678', 'G-ABCD1234', 'manual');
        self::assertSame(1, \substr_count($html, 'ym(12345678, "init", {clickmap:true});'));
        self::assertSame(1, \substr_count($html, "gtag('config','G-ABCD1234');"));
        self::assertSame(1, \substr_count($html, 'mc.yandex.ru/watch/12345678'), 'only the pixel authored by the site remains');
    }

    /** @return iterable<string, array{string, string}> */
    public static function quotedMetrikaEmbeds(): iterable
    {
        yield 'single quoted ID' => ['metrika-single-quoted', "ym('12345678', 'init', {clickmap:true});"];
        yield 'double quoted ID' => ['metrika-double-quoted', 'ym("12345678", "init", {clickmap:true});'];
    }

    #[Test]
    #[DataProvider('quotedMetrikaEmbeds')]
    public function autoRecognizesLegacyMetrikaWithAQuotedCounterId(string $embed, string $inline): void
    {
        $client = $this->client();
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234']);

        $html = $this->body($client, '/test-analytics/controller/' . $embed);

        self::assertResponseIsSuccessful();
        self::assertLoader($html, ga: 'G-ABCD1234');
        self::assertSame(1, \substr_count($html, $inline));
        self::assertSame(1, \substr_count($html, 'mc.yandex.ru/watch/12345678'), 'the pre-existing pixel is not duplicated');
    }

    #[Test]
    public function theLoaderUrlFollowsTheRequestBasePathOfASiteInstalledInASubdirectory(): void
    {
        $client = $this->client();
        $this->configure(['yandexMetrikaId' => '12345678']);

        $client->request('GET', '/subdir/test-analytics/controller', server: [
            'SCRIPT_NAME' => '/subdir/index.php',
            'SCRIPT_FILENAME' => '/tmp/itk-analytics-fixture/index.php',
            'PHP_SELF' => '/subdir/index.php',
        ]);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertLoader($html, '12345678');
        $src = (string) (new Crawler($html))->filter('script[data-itk-analytics]')->attr('src');
        self::assertStringStartsWith('/subdir/bundles/itkcms/', $src);
        self::assertStringContainsString('?', $src, 'the loader URL carries its version');
    }

    #[Test]
    public function onlyAPlainHtmlResponseOfTheMainRequestWithAGoodStatusIsTouched(): void
    {
        $this->client();
        $this->configure(['yandexMetrikaId' => '12345678']);
        $html = '<html><head></head><body><p>x</p></body></html>';
        $same = function (Response $response, string $what): void {
            $before = $response instanceof StreamedResponse || $response instanceof BinaryFileResponse ? null : $response->getContent();
            $this->filter($response);
            self::assertSame($before, $response instanceof StreamedResponse || $response instanceof BinaryFileResponse ? null : $response->getContent(), $what);
        };

        $same(new Response($html, 200, ['Content-Type' => 'application/xml']), 'xml');
        $same(new Response($html, 200, ['Content-Type' => 'text/plain']), 'text');
        $same(new JsonResponse(['html' => $html]), 'json');
        $same(new Response($html, 302, ['Content-Type' => 'text/html']), 'redirect');
        $same(new Response($html, 500, ['Content-Type' => 'text/html']), 'server error');
        $same(new Response('', 304, ['Content-Type' => 'text/html']), 'not modified');
        $same(new Response($html, 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']), 'compressed');
        $same(new Response('', 200, ['Content-Type' => 'text/html']), 'empty');
        $streamed = new StreamedResponse(static function () use ($html): void {
            echo $html;
        }, 200, ['Content-Type' => 'text/html']);
        $this->filter($streamed);
        \ob_start();
        $streamed->sendContent();
        self::assertSame($html, \ob_get_clean(), 'streamed');
        $file = new BinaryFileResponse(__FILE__, 200, ['Content-Type' => 'text/html']);
        $this->filter($file);
        self::assertSame(__FILE__, $file->getFile()->getPathname(), 'a file');

        $sub = $this->filter(new Response($html, 200, ['Content-Type' => 'text/html']), 'itk_page', HttpKernelInterface::SUB_REQUEST);
        self::assertSame($html, $sub->getContent(), 'a sub-request');
    }

    #[Test]
    public function contentLengthFollowsTheNewBody(): void
    {
        $this->client();
        $this->configure(['yandexMetrikaId' => '12345678']);
        $html = '<html><head></head><body><p>x</p></body></html>';

        $with = $this->filter(new Response($html, 200, ['Content-Type' => 'text/html', 'Content-Length' => (string) \strlen($html)]));
        self::assertGreaterThan(\strlen($html), \strlen((string) $with->getContent()));
        self::assertSame((string) \strlen((string) $with->getContent()), $with->headers->get('Content-Length'));

        $without = $this->filter(new Response($html, 200, ['Content-Type' => 'text/html']));
        self::assertFalse($without->headers->has('Content-Length') && '' === $without->headers->get('Content-Length'));
        self::assertNull($without->headers->get('Content-Length'), 'a response without a length does not get one');
    }

    #[Test]
    public function aLayoutThatAlreadyHasTheSameCounterDoesNotGetASecondOne(): void
    {
        $this->client();
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234', 'yandexVerification' => self::YANDEX_CODE, 'googleVerification' => self::GOOGLE_CODE]);
        $own = '<!DOCTYPE html><html><head><meta name="yandex-verification" content="' . self::YANDEX_CODE . '">'
            . '<script>(function(m,e,t,r,i,k,a){})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym(12345678, "init", {clickmap:true});</script>'
            . '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABCD1234"></script>'
            . '<meta name="google-site-verification" content="' . self::GOOGLE_CODE . '"></head><body><noscript><div><img src="https://mc.yandex.ru/watch/12345678" alt=""></div></noscript></body></html>';

        $response = $this->filter(new Response($own, 200, ['Content-Type' => 'text/html']));

        self::assertLoader((string) $response->getContent());
        $withoutLoader = \preg_replace('~<script[^>]+data-itk-analytics[^>]*></script>\n~', '', (string) $response->getContent());
        self::assertSame($own, $withoutLoader, 'the existing embeds, verification codes and pixel are left unchanged');
    }

    #[Test]
    public function aLayoutTagWithTheSameNameAndAnotherCodeDoesNotHideTheOwnersCode(): void
    {
        $this->client();
        $this->configure(['yandexVerification' => self::YANDEX_CODE]);
        $own = '<!DOCTYPE html><html><head><meta name="yandex-verification" content="layout-code-55"></head><body></body></html>';

        $html = (string) $this->filter(new Response($own, 200, ['Content-Type' => 'text/html']))->getContent();

        self::assertStringContainsString('content="layout-code-55"', $html);
        self::assertStringContainsString('content="' . self::YANDEX_CODE . '"', $html);
    }

    #[Test]
    public function aCounterOfAnotherNumberIsNotTakenForOurs(): void
    {
        $this->client();
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234']);
        $other = '<html><head><script src="https://mc.yandex.ru/metrika/tag.js"></script><script>ym(99999999, "init", {});</script>'
            . '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ZZZZ9999"></script></head><body>12345678</body></html>';

        $html = (string) $this->filter(new Response($other, 200, ['Content-Type' => 'text/html']))->getContent();

        self::assertStringContainsString('data-metrika="12345678"', $html);
        self::assertStringContainsString('data-ga="G-ABCD1234"', $html);
        self::assertStringContainsString('<noscript><div><img src="https://mc.yandex.ru/watch/12345678"', $html);
    }

    #[Test]
    public function aPageWithoutHeadOrBodyIsLeftAsItIs(): void
    {
        $this->client();
        $this->configure(['yandexMetrikaId' => '12345678', 'yandexVerification' => self::YANDEX_CODE]);
        $fragment = '<div>Только кусок</div>';

        self::assertSame($fragment, $this->filter(new Response($fragment, 200, ['Content-Type' => 'text/html']))->getContent());
    }

    #[Test]
    public function aPageCacheHitStillCarriesTheCounters(): void
    {
        $client = $this->client();
        $this->publish('/test-an-cache');
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234', 'yandexVerification' => self::YANDEX_CODE]);

        $first = $this->body($client, '/test-an-cache');
        self::assertSame('MISS', $client->getResponse()->headers->get('X-ITK-Cache'));
        $second = $this->body($client, '/test-an-cache');
        self::assertSame('HIT', $client->getResponse()->headers->get('X-ITK-Cache'));

        self::assertSame($first, $second);
        self::assertLoader($second, '12345678', 'G-ABCD1234');
        self::assertSame(1, \substr_count($second, 'yandex-verification'));

        $this->configure(['yandexMetrikaId' => '87654321']);
        $third = $this->body($client, '/test-an-cache');
        self::assertSame('MISS', $client->getResponse()->headers->get('X-ITK-Cache'), 'a changed counter is a new entry and a new tag');
        self::assertStringContainsString('data-metrika="87654321"', $third);
        self::assertStringNotContainsString('12345678', $third);
    }

    #[Test]
    public function aMissingPageGetsThemToo(): void
    {
        $client = $this->client();
        $this->configure(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234']);

        $client->catchExceptions(true);
        $html = $this->body($client, '/test-an-no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('data-metrika="12345678"', $html);
        self::assertStringContainsString('data-ga="G-ABCD1234"', $html);
    }

    #[Test]
    public function aNotModifiedAnswerIsNotTouched(): void
    {
        $client = $this->client();
        $this->publish('/test-an-304');
        $this->configure(['yandexMetrikaId' => '12345678']);

        $client->request('GET', '/test-an-304');
        $etag = (string) $client->getResponse()->headers->get('ETag');
        self::assertNotSame('', $etag);
        self::assertLoader((string) $client->getResponse()->getContent(), '12345678');

        $client->request('GET', '/test-an-304', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertSame($etag, $client->getResponse()->headers->get('ETag'), 'the tag that already covers the counters');
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\CriticalCss\Model\ExtractedCss;
use Itk\Cms\CriticalCss\Model\ExtractionFailed;
use Itk\Cms\CriticalCss\Model\GenerateCriticalCss;
use Itk\Cms\CriticalCss\Model\SourcePage;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Tests\App\FakeCssExtractor;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Twig\Environment;

/**
 * Критический CSS живой страницы: что видит посетитель. Готовая строка таблицы — `<style media>` и ссылка с подменой; всё остальное (нет строки,
 * устарела, не вышло, предпросмотр, выключено) — обычный `<link>`. Кэш страниц и ETag меняются вместе со строкой.
 */
final class CriticalCssTest extends WebTestCase
{
    private const CSS = '/css/test-critical.css';

    /** @var list<string> */
    private array $created = [];
    private bool $domainTouched = false;
    private bool $domainWasSet = false;
    private mixed $domainBefore = '';

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', $options['debug'] ?? false, null, '', [], [], $options['itk'] ?? self::on());
    }

    /** @return array<string, mixed> the itk_cms config of a site with the feature on */
    private static function on(bool $enabled = true): array
    {
        return ['critical_css' => ['enabled' => $enabled, 'stylesheets' => [self::CSS]]];
    }

    private static function cssFile(): string
    {
        return \dirname(__DIR__) . '/App/public' . self::CSS;
    }

    protected function setUp(): void
    {
        @\mkdir(\dirname(self::cssFile()), 0o777, true);
        \file_put_contents(self::cssFile(), 'body{margin:0}');
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
        if ($this->domainTouched) {
            $settings = $c->get(SettingsService::class);
            if ($this->domainWasSet) {
                $settings->set('domain', $this->domainBefore);
            } else {
                $c->get(EntityManagerInterface::class)->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'domain'")->execute();
            }
        }
        $c->get(PageCache::class)->clear();
        $c->get(FakeCssExtractor::class)->forget();
        $c->get('messenger.transport.critical_css')->reset();
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->catchExceptions(false);
        $client->disableReboot(); // the in-memory queue and the fake browser must outlive one request: the tests read them between visits
        self::getContainer()->get(PageCache::class)->clear();
        // the host the cache of pages accepts («Настройки → Сайт»)
        $settings = self::getContainer()->get(SettingsService::class);
        if (!$this->domainTouched) {
            $this->domainTouched = true;
            $this->domainWasSet = $settings->isSet('domain');
            $this->domainBefore = $settings->get('domain', null, '');
        }
        $settings->set('domain', 'localhost');

        return $client;
    }

    private function publish(string $url, string $title = 'Критический'): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => $title, 'url' => $url]);
        $this->created[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    private function translation(string $id): PageTranslation
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $t = self::getContainer()->get(PageRepository::class)->find($id)?->translation('ru');
        self::assertNotNull($t);

        return $t;
    }

    /** A row written by hand, as the worker would write it for the basis the page wants right now. */
    private function storeRow(string $id, bool $ready, string $css = 'h1{color:red}'): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $t = $this->translation($id);
        $basis = self::getContainer()->get(CriticalCssService::class)->wanted($t)->value;
        $row = $em->find(CriticalCss::class, $t->id) ?? CriticalCss::for($t);
        if ($ready) {
            $row->markReady($basis, 'inputs', [['media' => '(max-width:1365.98px)', 'css' => $css], ['media' => '(min-width:1366px)', 'css' => $css . 'p{x:1}']], new \DateTimeImmutable());
        } else {
            $row->markFailed($basis, 'inputs', 'crittr said no', new \DateTimeImmutable());
        }
        $em->persist($row);
        $em->flush();
        $em->clear();
    }

    private function visit(KernelBrowser $client, string $url): Response
    {
        $client->request('GET', $url);

        return $client->getResponse();
    }

    private static function assertPlainLink(string $html, bool $versioned = true): void
    {
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('<noscript>', $html);
        self::assertMatchesRegularExpression($versioned ? '~<link rel="stylesheet" href="' . \preg_quote(self::CSS, '~') . '\?v=[0-9a-f]{8}">~' : '~<link rel="stylesheet" href="' . \preg_quote(self::CSS, '~') . '">~', $html);
    }

    private static function assertInlined(string $html, string $css = 'h1{color:red}'): void
    {
        self::assertStringContainsString('<style media="(max-width:1365.98px)">' . $css . '</style>', $html);
        self::assertStringContainsString('<style media="(min-width:1366px)">' . $css . 'p{x:1}</style>', $html);
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="' . \preg_quote(self::CSS, '~') . '\?v=[0-9a-f]{8}" media="print" onload="this\.media=\'all\'"><noscript><link rel="stylesheet" href="' . \preg_quote(self::CSS, '~') . '\?v=[0-9a-f]{8}"></noscript>~', $html);
    }

    #[Test]
    public function aReadyRowIsInlinedAndAnythingElseIsThePlainLink(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-ready');

        self::assertPlainLink((string) $this->visit($client, '/test-cc-ready')->getContent());

        $this->storeRow($id, true);
        self::assertInlined((string) $this->visit($client, '/test-cc-ready')->getContent());

        $this->storeRow($id, false);
        self::assertPlainLink((string) $this->visit($client, '/test-cc-ready')->getContent());
    }

    #[Test]
    public function theCacheAndTheEtagFollowTheRow(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-etag');

        $before = $this->visit($client, '/test-cc-etag');
        self::assertSame('MISS', $before->headers->get('X-ITK-Cache'));
        self::assertNotNull($before->getEtag());
        self::assertSame('HIT', $this->visit($client, '/test-cc-etag')->headers->get('X-ITK-Cache'));

        $this->storeRow($id, true);
        $after = $this->visit($client, '/test-cc-etag');
        self::assertSame('MISS', $after->headers->get('X-ITK-Cache'), 'a ready row is a new page for the cache');
        self::assertNotSame($before->getEtag(), $after->getEtag());
        self::assertInlined((string) $after->getContent());

        $hit = $this->visit($client, '/test-cc-etag');
        self::assertSame('HIT', $hit->headers->get('X-ITK-Cache'));
        self::assertInlined((string) $hit->getContent());

        $client->request('GET', '/test-cc-etag', [], [], ['HTTP_IF_NONE_MATCH' => (string) $before->getEtag()]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'the old tag is not the page any more');
    }

    #[Test]
    public function aChangedSnapshotOrAChangedStylesheetBringsBackThePlainLink(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-stale');
        $this->storeRow($id, true);
        self::assertInlined((string) $this->visit($client, '/test-cc-stale')->getContent());

        $pages = self::getContainer()->get(PageService::class);
        $pages->update($id, null, ['title' => 'Другой заголовок']);
        $pages->publish($id);
        self::assertPlainLink((string) $this->visit($client, '/test-cc-stale')->getContent());

        $this->storeRow($id, true);
        self::assertInlined((string) $this->visit($client, '/test-cc-stale')->getContent());

        \file_put_contents(self::cssFile(), 'body{margin:0;padding:0}');
        self::assertPlainLink((string) $this->visit($client, '/test-cc-stale')->getContent());
    }

    #[Test]
    public function theSameSnapshotPublishedAgainIsStillReady(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-same');
        $this->storeRow($id, true);

        self::getContainer()->get(PageService::class)->publish($id);

        self::assertInlined((string) $this->visit($client, '/test-cc-same')->getContent());
    }

    #[Test]
    public function previewNeverInlines(): void
    {
        $this->client();
        $id = $this->publish('/test-cc-preview');
        $this->storeRow($id, true);

        $requests = self::getContainer()->get(RequestStack::class);
        $requests->push(Request::create('/admin/preview/' . $id)); // the layout reads app.request, as PreviewController's request would give it
        try {
            $html = (string) self::getContainer()->get(PageRenderer::class)->renderPreview($id)->getContent();
        } finally {
            $requests->pop();
        }

        self::assertStringNotContainsString('<style media', $html);
        self::assertStringNotContainsString('<noscript>', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="' . self::CSS . '?v=', $html);
    }

    #[Test]
    public function withTheFeatureOffTheLinkIsTodaysPlainLink(): void
    {
        $client = $this->client(['itk' => self::on(false)]);
        $id = $this->publish('/test-cc-off');
        $this->storeRow($id, true);

        self::assertPlainLink((string) $this->visit($client, '/test-cc-off')->getContent(), false);
    }

    /** @return list<GenerateCriticalCss> what was put into the critical_css transport since the last request (the in-memory queue of a test is emptied by the next one) */
    private function queued(): array
    {
        return \array_map(static fn (Envelope $e): object => $e->getMessage(), self::getContainer()->get('messenger.transport.critical_css')->getSent());
    }

    /**
     * Runs the worker over these messages, as `messenger:consume critical_css` would.
     *
     * @param list<GenerateCriticalCss> $messages
     */
    private function consume(array $messages): void
    {
        $bus = self::getContainer()->get('messenger.bus.default');
        foreach ($messages as $message) {
            $bus->dispatch((new Envelope($message))->with(new ReceivedStamp('critical_css')));
        }
        self::getContainer()->get('messenger.transport.critical_css')->reset();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function fake(): FakeCssExtractor
    {
        return self::getContainer()->get(FakeCssExtractor::class);
    }

    #[Test]
    public function publishingQueuesOneMessageAndAnAutosaveDoesNot(): void
    {
        $this->client();
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'В очередь', 'url' => '/test-cc-queue']);
        $this->created[] = $page['id'];
        self::assertSame([], $this->queued(), 'a draft is not a page visitors see');

        $pages->update($page['id'], null, ['title' => 'В очередь (черновик)']);
        self::assertSame([], $this->queued(), 'an autosave changes the draft only');

        $pages->publish($page['id']);
        $t = $this->translation($page['id']);
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($t->id, $queued[0]->translationId);
        self::assertSame(When::Outdated, $queued[0]->when);

        $pages->update($page['id'], null, ['title' => 'Опубликовано, правится']);
        self::assertCount(1, $this->queued(), 'an autosave of a live page does not ask again');
    }

    #[Test]
    public function theWorkerMakesThePageReadyAndAChangedPublishMakesItStaleAgain(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-worker');
        $asked = $this->queued();
        self::assertCount(1, $asked);
        self::assertPlainLink((string) $this->visit($client, '/test-cc-worker')->getContent());

        $this->consume($asked);

        $html = (string) $this->visit($client, '/test-cc-worker')->getContent();
        self::assertStringContainsString('<style media="(max-width:1365.98px)">h1{width:390px}</style>', $html);
        self::assertStringContainsString('<style media="(min-width:1366px)">h1{width:1366px}</style>', $html);
        self::assertStringContainsString('media="print" onload="this.media=\'all\'"><noscript>', $html);
        $calls = $this->fake()->calls;
        self::assertCount(1, $calls);
        self::assertSame('body{margin:0}', $calls[0]['css'], 'the source CSS is the configured file, prepared');
        self::assertSame('/test-cc-worker', $calls[0]['pages'][0]->path);
        self::assertStringContainsString('<link rel="stylesheet" href="' . self::CSS . '?v=', $calls[0]['pages'][0]->html, 'the page is rendered by PHP with the plain link, never with its own critical CSS');
        self::assertStringNotContainsString('<style', $calls[0]['pages'][0]->html);

        $pages = self::getContainer()->get(PageService::class);
        $pages->update($id, null, ['title' => 'Изменилась']);
        $pages->publish($id);
        $asked = $this->queued();
        self::assertCount(1, $asked, 'the new snapshot is asked for once');
        self::assertPlainLink((string) $this->visit($client, '/test-cc-worker')->getContent());

        $this->consume($asked);
        self::assertStringContainsString('<style media="(max-width:1365.98px)">', (string) $this->visit($client, '/test-cc-worker')->getContent());
    }

    #[Test]
    public function aFailedExtractionIsStoredAndThePageKeepsThePlainLink(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-fail');
        $this->fake()->answer = static fn (): ExtractionFailed => new ExtractionFailed('Chrome did not start');

        $this->consume($this->queued());

        self::assertPlainLink((string) $this->visit($client, '/test-cc-fail')->getContent());
        $row = self::getContainer()->get(EntityManagerInterface::class)->find(CriticalCss::class, $this->translation($id)->id);
        self::assertNotNull($row);
        self::assertSame(CriticalCss::FAILED, $row->status);
        self::assertSame('Chrome did not start', $row->error);
        self::assertNull($row->styles);

        self::assertSame([], $this->queued());
        $this->visit($client, '/test-cc-fail');
        self::assertSame([], $this->queued(), 'a failure on the same basis is not retried by a visit (no Chrome loop)');
    }

    #[Test]
    public function anEmptyOrOversizedResultIsAFailureNotAnEmptyStyleTag(): void
    {
        $client = $this->client();
        $id = $this->publish('/test-cc-empty');
        $this->fake()->answer = static fn (): ExtractedCss => new ExtractedCss(['', '']);

        $this->consume($this->queued());

        $html = (string) $this->visit($client, '/test-cc-empty')->getContent();
        self::assertPlainLink($html);
        $row = self::getContainer()->get(EntityManagerInterface::class)->find(CriticalCss::class, $this->translation($id)->id);
        self::assertSame('empty', $row?->error);
    }

    #[Test]
    public function anUnchangedPageAfterAStylesheetTouchReusesItsStylesWithoutTheBrowser(): void
    {
        $client = $this->client();
        $this->publish('/test-cc-reuse');
        $this->consume($this->queued());
        self::assertCount(1, $this->fake()->calls);

        \touch(self::cssFile(), \time() + 2); // a deploy that copied the same file: the stat changes, the content does not
        self::assertPlainLink((string) $this->visit($client, '/test-cc-reuse')->getContent());
        $asked = $this->queued();
        self::assertCount(1, $asked, 'the render asked the worker once');
        $this->visit($client, '/test-cc-reuse');
        self::assertSame([], $this->queued(), 'and not again for the same basis');

        $this->consume($asked);

        self::assertCount(1, $this->fake()->calls, 'no browser run: the rendered HTML and CSS were byte-identical');
        self::assertStringContainsString('<style media="(max-width:1365.98px)">h1{width:390px}</style>', (string) $this->visit($client, '/test-cc-reuse')->getContent());
    }

    /** @param array<string, mixed> $options */
    private function command(array $options = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:critical-css:generate'));
        $tester->execute($options, ['interactive' => false]);

        return $tester;
    }

    #[Test]
    public function theCommandQueuesWhatIsNotReadyAndRetriesFailures(): void
    {
        $this->client();
        $id = $this->publish('/test-cc-cmd');
        $this->consume($this->queued());
        $other = $this->publish('/test-cc-cmd-other');
        $this->storeRow($other, false);
        self::getContainer()->get('messenger.transport.critical_css')->reset();

        $dry = $this->command(['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('/test-cc-cmd-other (ru): Failed', $dry->getDisplay());
        self::assertStringNotContainsString('/test-cc-cmd (ru)', $dry->getDisplay(), 'a ready page is not listed');
        self::assertSame([], $this->queued(), 'a dry run sends nothing');

        $run = $this->command();
        self::assertSame(0, $run->getStatusCode(), $run->getDisplay());
        $queued = $this->queued();
        self::assertSame([$this->translation($other)->id], \array_map(static fn (GenerateCriticalCss $m): string => $m->translationId, $queued), 'the failed page is retried, the ready one is left alone');
        self::assertSame(When::NotReady, $queued[0]->when);
        self::getContainer()->get('messenger.transport.critical_css')->reset();

        $all = $this->command(['--all' => true, '--page' => $id]);
        self::assertSame(0, $all->getStatusCode(), $all->getDisplay());
        $queued = $this->queued();
        self::assertCount(1, $queued);
        self::assertSame($this->translation($id)->id, $queued[0]->translationId);
        self::assertSame(When::Always, $queued[0]->when);
    }

    #[Test]
    public function theCommandWithNowMakesThePagesHereAndFailsWhenOneFails(): void
    {
        $client = $this->client();
        $this->publish('/test-cc-now');
        $broken = $this->publish('/test-cc-now-broken');
        self::getContainer()->get('messenger.transport.critical_css')->reset();
        $this->fake()->answer = static fn (SourcePage $p): ExtractedCss|ExtractionFailed => '/test-cc-now-broken' === $p->path ? new ExtractionFailed('selector bug') : new ExtractedCss(['a{x:1}', 'a{x:1}']);

        $now = $this->command(['--now' => true]);

        self::assertSame(1, $now->getStatusCode(), $now->getDisplay());
        self::assertStringContainsString('/test-cc-now-broken (ru): selector bug', $now->getDisplay());
        self::assertStringContainsString('<style>a{x:1}</style>', (string) $this->visit($client, '/test-cc-now')->getContent(), 'identical viewports merge into one block without media');
        self::assertPlainLink((string) $this->visit($client, '/test-cc-now-broken')->getContent());
        self::assertSame([], $this->queued(), '--now queues nothing');

        $this->fake()->answer = null;
        $again = $this->command(['--now' => true, '--page' => $broken]);
        self::assertSame(0, $again->getStatusCode(), $again->getDisplay());
        self::assertStringContainsString('<style media="(max-width:1365.98px)">h1{width:390px}</style>', (string) $this->visit($client, '/test-cc-now-broken')->getContent());
    }

    #[Test]
    public function theCommandRefusesWhenTheFeatureIsOff(): void
    {
        $this->client(['itk' => self::on(false)]);

        $off = $this->command();

        self::assertSame(2, $off->getStatusCode());
        self::assertStringContainsString('itk_cms.critical_css.enabled', $off->getDisplay());
    }

    #[Test]
    public function aNonceGoesOnTheStyleTagsAndTheSwapLink(): void
    {
        $this->client();
        $state = self::getContainer()->get(RenderState::class);
        $state->critical = CriticalStyles::fromStored([['media' => null, 'css' => 'a{x:1}</style><script>']]);
        try {
            $html = self::getContainer()->get(Environment::class)->createTemplate("{{ itk_stylesheets({nonce: 'n1'}) }}")->render();
        } finally {
            $state->reset();
        }

        self::assertStringContainsString('<style nonce="n1">a{x:1}<\/style><script></style>', $html);
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="[^"]+" nonce="n1" media="print" onload="this\.media=\'all\'">~', $html);
    }
}

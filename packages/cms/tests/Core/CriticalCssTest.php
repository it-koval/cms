<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
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
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);
        $client->catchExceptions(false);
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

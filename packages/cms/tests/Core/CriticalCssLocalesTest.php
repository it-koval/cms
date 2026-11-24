<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Command\GenerateCriticalCssCommand;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\H1Rule;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\CriticalCss\CriticalCssGenerator;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\CriticalCss\RequestOnPublish;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Rendering\PageValidators;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Repository\SeoRuleRepository;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Resource\Record;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\SchemaOrg\SchemaOrgInjector;
use Itk\Cms\Security\Viewer;
use Itk\Cms\Tests\App\FakeCssExtractor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** In-memory repositories exercise real services; the kernel only constructs unused renderer dependencies. */
#[Group('no-database')]
final class CriticalCssLocalesTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        try {
            if (self::$booted) {
                self::assertFalse(self::getContainer()->get(Connection::class)->isConnected(), 'Locale fixtures must not connect to the database.');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function locales(): ContentLocales
    {
        return new ContentLocales('ru', ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']]); // i18n-ignore: configured names
    }

    private function translation(string $locale, string $path, bool $live = true, ?Page $page = null): PageTranslation
    {
        $page ??= new Page();
        $t = new PageTranslation($page, $locale);
        $page->translations->set($locale, $t);
        $t->draft = ['url' => '/draft', 'title' => 'Draft'];
        if ($live) {
            $t->published = ['url' => $path, 'title' => 'Published', 'seo' => ['robots' => 'noindex']];
            $t->publishedUrl = $path;
            $t->status = PageTranslation::PUBLISHED;
        }

        return $t;
    }

    private function stylesheets(): Stylesheets
    {
        return new Stylesheets(true, [], [['width' => 375, 'height' => 800, 'mobile' => true]], [], [], 30, __DIR__ . '/../App');
    }

    private function service(EntityManagerInterface $em, int $dispatches = 0): CriticalCssService
    {
        $styles = $this->stylesheets();
        $clock = new MockClock();
        $validators = new PageValidators($this->createStub(Connection::class), false, false, '', 'LocalesFixture', $clock, $styles);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly($dispatches))->method('dispatch')->willReturnCallback(static fn ($message): Envelope => new Envelope($message));

        return new CriticalCssService($em, $styles, $validators, $bus, new ArrayAdapter(), new NullLogger(), $this->locales());
    }

    private function renderer(): PageRenderer
    {
        $schema = (new SchemaBuilder())->build();
        $container = self::getContainer();
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('findAll')->willReturn([]);
        $settings = new SettingsService(
            $this->createStub(EntityManagerInterface::class),
            $repository,
            $schema,
            $container->get(FieldValues::class),
            $container->get(HistoryService::class),
            $container->get(FieldAccess::class),
            'ru',
        );
        $requests = new RequestStack();
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../Fixtures/critical-css'));
        $twig->addFunction(new TwigFunction('request_path', static fn (): string => $requests->getCurrentRequest()?->getPathInfo() ?? 'missing request'));
        $rules = $this->createStub(SeoRuleRepository::class);
        $rules->method('findActive')->willReturn([]);
        $seo = new SeoResolver($rules, $requests, 'ru');
        $em = $this->createStub(EntityManagerInterface::class);

        return new PageRenderer(
            $twig,
            $schema,
            new H1Rule($schema),
            $settings,
            $container->get(PageService::class),
            new RenderState(),
            $seo,
            $requests,
            new AdminTheme($em, $repository),
            $this->service($em),
            new PublicUrl($requests, $settings, 'https://example.test'),
            $container->get(SchemaOrgInjector::class),
            'ru',
            contentLocales: $this->locales(),
        );
    }

    #[Test]
    public function serviceRejectsDisabledAndUnpublishedVersionsBeforeReadingOrQueueing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('find');
        $critical = $this->service($em);
        foreach ([$this->translation('en', '/about'), $this->translation('en', '/en-about'), $this->translation('ru', '/kk/about'), $this->translation('de', '/de/test-critical-css-disabled'), $this->translation('kk', '/kk/test-critical-css-unpublished', false)] as $t) {
            self::assertFalse($critical->request($t));
            self::assertNull($critical->forLiveRender($t));
        }
    }

    #[Test]
    public function publishListenerQueuesOnlyEnabledLiveVersionsAndAllowsNoindex(): void
    {
        $enabled = $this->translation('kk', '/kk/test-critical-css-noindex');
        $disabled = $this->translation('de', '/de/test-critical-css-disabled');
        $unpublished = $this->translation('tr', '/tr/test-critical-css-unpublished', false);
        $unserved = $this->translation('en', '/test-critical-css-unserved');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $critical = $this->service($em, 1);
        $translations = $this->createStub(PageTranslationRepository::class);
        $translations->method('findOneBy')->willReturnOnConsecutiveCalls($disabled, $unpublished, $unserved, $enabled);
        $listener = new RequestOnPublish($critical, $translations, new NullLogger(), $this->locales());
        foreach ([$disabled, $unpublished, $unserved, $enabled] as $t) {
            $record = new Record($t->page->id, 'page', 'default', $t->locale, [], $t->status);
            $listener(new RecordPublished('default', 'page', $record, [], Viewer::system()));
        }
    }

    #[Test]
    public function workerSkipsPreviouslyQueuedDisabledAndUnpublishedVersions(): void
    {
        $disabled = $this->translation('de', '/de/test-critical-css-disabled');
        $removed = $this->translation('kk', '/kk/test-critical-css-unpublished', false);
        $unserved = $this->translation('en', '/about');
        $collision = $this->translation('ru', '/kk/about');
        $bad = [$disabled->id => $disabled, $removed->id => $removed, $unserved->id => $unserved, $collision->id => $collision];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class, string $id): PageTranslation => $bad[$id]);
        $extractor = new FakeCssExtractor();
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeStatement');
        $generator = new CriticalCssGenerator($em, $db, $this->service($em), $this->stylesheets(), $this->renderer(), $extractor, new MockClock(), new NullLogger(), $this->locales());

        $report = $generator->generate(\array_fill_keys(\array_keys($bad), When::Always));

        self::assertSame(4, $report->skipped);
        self::assertSame([], $report->failed);
        self::assertSame([], $extractor->calls);
    }

    #[Test]
    public function extractorAndSyntheticRequestSeeTheFullPublishedPathIncludingNoindex(): void
    {
        $t = $this->translation('kk', '/kk/test-critical-css-about');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class): ?PageTranslation => PageTranslation::class === $class ? $t : null);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('executeStatement')->willReturn(1);
        $extractor = new FakeCssExtractor();
        $generator = new CriticalCssGenerator($em, $db, $this->service($em), $this->stylesheets(), $this->renderer(), $extractor, new MockClock(), new NullLogger(), $this->locales());

        $report = $generator->generate([$t->id => When::Always]);

        self::assertSame([], $report->failed);
        self::assertSame(1, $report->generated);
        self::assertSame('/kk/test-critical-css-about', $extractor->calls[0]['pages'][0]->path);
        self::assertSame('/kk/test-critical-css-about kk Published noindex', $extractor->calls[0]['pages'][0]->html);
    }

    #[Test]
    public function sourceRendererRefusesUnservedVersions(): void
    {
        foreach ([$this->translation('en', '/about'), $this->translation('en', '/en-about'), $this->translation('ru', '/kk/about'), $this->translation('de', '/de/test-critical-css-disabled'), $this->translation('kk', '/kk/test-critical-css-unpublished', false)] as $t) {
            try {
                $this->renderer()->renderSource($t);
                self::fail('Unserved versions must not be rendered for the extractor.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('live version', $e->getMessage());
            }
        }
    }

    #[Test]
    public function commandSelectionFiltersDisabledLanguagesIncludingAnExplicitPage(): void
    {
        $enabled = $this->translation('kk', '/kk/test-critical-css-about');
        $disabled = $this->translation('de', '/de/test-critical-css-about', page: $enabled->page);
        $draft = $this->translation('tr', '/tr/test-critical-css-about', false, $enabled->page);
        $unserved = $this->translation('en', '/test-critical-css-unserved', page: $enabled->page);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $critical = $this->service($em);
        $generator = new CriticalCssGenerator($em, $this->createStub(Connection::class), $critical, $this->stylesheets(), $this->renderer(), new FakeCssExtractor(), new MockClock(), new NullLogger(), $this->locales());
        $pages = $this->createStub(PageRepository::class);
        $pages->method('find')->willReturn($enabled->page);
        $translations = $this->createStub(PageTranslationRepository::class);
        $translations->method('findAllLive')->willReturn([$enabled, $disabled, $draft, $unserved]);
        $tester = new CommandTester(new GenerateCriticalCssCommand($this->stylesheets(), $critical, $generator, $pages, $translations, $this->locales()));

        foreach ([[], ['--page' => $enabled->page->id]] as $options) {
            self::assertSame(0, $tester->execute(['--dry-run' => true] + $options));
            self::assertStringContainsString('/kk/test-critical-css-about', $tester->getDisplay());
            self::assertStringNotContainsString('/de/test-critical-css-about', $tester->getDisplay());
            self::assertStringNotContainsString('/tr/test-critical-css-about', $tester->getDisplay());
            self::assertStringNotContainsString('/test-critical-css-unserved', $tester->getDisplay());
        }
        self::assertSame(0, $tester->execute(['--dry-run' => true, '--page' => $enabled->page->id, '--locale' => 'de']));
        self::assertStringNotContainsString('/de/test-critical-css-about', $tester->getDisplay());
    }
}

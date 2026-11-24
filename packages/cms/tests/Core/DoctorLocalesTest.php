<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\MenuArea;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Field\Text;
use Itk\Cms\Health\AppSecretHealthCheck;
use Itk\Cms\Health\DatabaseHealthCheck;
use Itk\Cms\Health\Doctor;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\HealthService;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Health\UpdateChecker;
use Itk\Cms\Health\UploadsHealthCheck;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Repository\MenuAreaRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Repository\RequestRepository;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Schema\SchemaLint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Repositories are in-memory doubles; the kernel only constructs unused service dependencies. */
#[Group('no-database')]
final class DoctorLocalesTest extends KernelTestCase
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

    /**
     * @param list<array{pageId: string, draftUrl: ?string, publishedUrl: ?string}> $addresses
     * @param array<array-key, mixed> $shared
     */
    private function doctor(?ContentLocales $locales = null, array $addresses = [], bool $home = true, ?string $base = 'https://example.test', string $dir = '', array $shared = [], ?Request $request = null): Doctor
    {
        $builder = new SchemaBuilder();
        $builder->settings('localized')->fields(Text::make('Greeting', 'greeting')->translatable());
        $builder->menuArea('header', 'Header');
        $schema = $builder->build();
        $rows = [new Setting('greeting', 'ru', 'Hello')];
        foreach ($shared as $key => $value) {
            $rows[] = new Setting($key, Setting::ALL, $value);
        }
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('findAll')->willReturn($rows);
        $container = self::getContainer();
        $settings = new SettingsService(
            $this->createStub(EntityManagerInterface::class),
            $repository,
            $schema,
            $container->get(FieldValues::class),
            $container->get(HistoryService::class),
            $container->get(FieldAccess::class),
            'ru',
        );
        $areas = $this->createStub(MenuAreaRepository::class);
        $area = new MenuArea('header', 'Header');
        $area->items['ru'] = [['label' => 'Home']];
        $areas->method('findAll')->willReturn([$area]);
        $translations = $this->createStub(PageTranslationRepository::class);
        $translations->method('findAddresses')->willReturnCallback(static fn (string $locale): array => \array_values(\array_filter($addresses, static fn (array $row): bool => ($row['locale'] ?? 'ru') === $locale)));
        $translations->method('findLive')->willReturnCallback(static fn (string $locale, string $path): ?PageTranslation => $home && 'ru' === $locale && '/' === $path ? new PageTranslation(new Page(), 'ru') : null);
        $requests = new RequestStack();
        if (null !== $request) {
            $requests->push($request);
        }
        $url = new PublicUrl($requests, $settings, $base, debug: true);
        $services = new ServiceLocator([
            SettingsService::class => static fn () => $settings,
            Schema::class => static fn () => $schema,
            MenuAreaRepository::class => static fn () => $areas,
            PublicUrl::class => static fn () => $url,
        ]);
        $db = $this->createStub(Connection::class);

        return new Doctor(
            new AppSecretHealthCheck('locale-test', $this->createStub(\Itk\Cms\Repository\UserTwoFactorRepository::class), new \Itk\Cms\Security\TwoFactor\SecretBox('locale-test')),
            new UploadsHealthCheck($dir),
            new DatabaseHealthCheck($db),
            new ImageVariants($dir, '/uploads', [375], 80, 1000000),
            new MessengerMonitor(new ServiceLocator([])),
            $translations,
            $db,
            $this->createStub(Security::class),
            new AdminPath($this->createStub(UrlGeneratorInterface::class), '/admin'),
            $container->get(SchemaLint::class),
            $this->createStub(DependencyFactory::class),
            $services,
            $dir,
            'ru',
            new \Itk\Cms\Health\WebpHealthCheck(new ImageVariants($dir, '/uploads', [375], 80, 1000000)),
            $locales ?? $this->locales(),
        );
    }

    /** @return array<string, HealthResult> */
    private function checks(Doctor $doctor): array
    {
        $out = [];
        foreach ($doctor->localeChecks() as [$name, $result]) {
            $out[$name] = $result;
        }

        return $out;
    }

    #[Test]
    public function listsTheMainLanguagePrefixesAndEachMissingSecondaryHome(): void
    {
        $checks = $this->checks($this->doctor());
        $languages = $checks[I18n::t('ops.doctor_name_languages')];
        self::assertSame(HealthResult::OK, $languages->status);
        self::assertStringContainsString('ru (без префикса)', $languages->message);
        self::assertStringContainsString('kk /kk', $languages->message);
        foreach (['kk', 'tr', 'zh', 'en'] as $code) {
            $home = $checks[I18n::t('ops.doctor_name_locale_home', ['locale' => $code])];
            self::assertSame(HealthResult::WARN, $home->status);
            self::assertStringContainsString('/' . $code, $home->message);
        }
        $failed = $this->checks($this->doctor(home: false));
        self::assertSame(HealthResult::FAIL, $failed[I18n::t('ops.doctor_name_locale_home', ['locale' => 'ru'])]->status);
    }

    #[Test]
    public function warnsForLiveAndDraftPrefixCollisionsWithABoundedList(): void
    {
        $addresses = [['pageId' => 'safe', 'draftUrl' => '/kk-news', 'publishedUrl' => '/about/kk']];
        for ($i = 0; $i < 12; ++$i) {
            $addresses[] = ['pageId' => (string) $i, 'draftUrl' => '/kk/draft-' . $i, 'publishedUrl' => '/tr/live-' . $i];
        }
        $check = $this->checks($this->doctor(addresses: $addresses))[I18n::t('ops.doctor_name_locale_conflicts')];
        self::assertSame(HealthResult::WARN, $check->status);
        self::assertStringContainsString('/kk/draft-0', $check->message);
        self::assertStringContainsString('14', $check->message);
        self::assertStringNotContainsString('/kk-news', $check->message);
        self::assertStringNotContainsString('/tr/live-9', $check->message);
    }

    #[Test]
    public function warnsAboutSecondaryVersionsOutsideTheirOwnPrefix(): void
    {
        $checks = $this->checks($this->doctor(addresses: [
            ['pageId' => 'one', 'locale' => 'en', 'draftUrl' => '/about', 'publishedUrl' => '/en-about'],
            ['pageId' => 'two', 'locale' => 'kk', 'draftUrl' => '/en/wrong', 'publishedUrl' => '/kk/valid'],
        ]));
        $check = $checks[I18n::t('ops.doctor_name_locale_conflicts')];
        self::assertSame(HealthResult::WARN, $check->status);
        foreach (['/about', '/en-about', '/en/wrong'] as $path) {
            self::assertStringContainsString($path, $check->message);
        }
        self::assertStringNotContainsString('/kk/valid', $check->message);
    }

    #[Test]
    public function warnsAboutMissingSeoBaseEmptySettingsAndMenuAreasPerLanguage(): void
    {
        $checks = $this->checks($this->doctor(base: null));
        self::assertSame(HealthResult::WARN, $checks[I18n::t('ops.doctor_name_seo_base')]->status);
        self::assertStringContainsString('hreflang', $checks[I18n::t('ops.doctor_name_seo_base')]->message);
        self::assertStringContainsString('sitemap', $checks[I18n::t('ops.doctor_name_seo_base')]->message);
        foreach (['ru', 'kk', 'tr', 'zh', 'en'] as $code) {
            $settings = $checks[I18n::t('ops.doctor_name_locale_settings', ['locale' => $code])];
            $menus = $checks[I18n::t('ops.doctor_name_locale_menus', ['locale' => $code])];
            self::assertSame('ru' === $code ? HealthResult::OK : HealthResult::WARN, $settings->status);
            self::assertSame('ru' === $code ? HealthResult::OK : HealthResult::WARN, $menus->status);
            if ('ru' !== $code) {
                self::assertStringContainsString('greeting', $settings->message);
                self::assertStringContainsString('header', $menus->message);
            }
        }
    }

    #[Test]
    public function theSeoBaseUsesTheSiteConfigurationEvenInATrustedRequest(): void
    {
        $trusted = Request::getTrustedHosts();
        Request::setTrustedHosts(['^trusted\.example$']);
        try {
            $request = Request::create('http://trusted.example:8080/kk');
            $name = I18n::t('ops.doctor_name_seo_base');
            $missing = $this->checks($this->doctor(base: null, request: $request))[$name];
            self::assertSame(HealthResult::WARN, $missing->status);
            self::assertSame(I18n::t('ops.doctor_seo_base_missing'), $missing->message);

            $fallback = $this->checks($this->doctor(base: null, shared: ['domain' => 'Configured.Example'], request: $request))[$name];
            self::assertSame(HealthResult::OK, $fallback->status);
            self::assertSame('https://configured.example', $fallback->message);
        } finally {
            Request::setTrustedHosts($trusted);
        }
    }

    #[Test]
    public function recognizesBothDraftAndLiveConflictsAndSavedCrawlerOverrides(): void
    {
        $doctor = $this->doctor(addresses: [
            ['pageId' => 'draft', 'draftUrl' => '/kk/draft', 'publishedUrl' => null],
            ['pageId' => 'live', 'draftUrl' => '/safe', 'publishedUrl' => '/tr/live'],
        ], shared: ['robotsTxt' => 'User-agent: *', 'llmsTxt' => '# Manual']);
        $checks = $this->checks($doctor);
        $conflicts = $checks[I18n::t('ops.doctor_name_locale_conflicts')];
        self::assertSame(HealthResult::WARN, $conflicts->status);
        self::assertStringContainsString('/kk/draft', $conflicts->message);
        self::assertStringContainsString('/tr/live', $conflicts->message);
        $overrides = $checks[I18n::t('ops.doctor_name_crawler_overrides')];
        self::assertSame(HealthResult::WARN, $overrides->status);
        self::assertStringContainsString('robots.txt', $overrides->message);
        self::assertStringContainsString('llms.txt', $overrides->message);
    }

    #[Test]
    public function siteHealthUsesTheSameLocaleDiagnosticsAndDoctorCanSkipTheirSecondRun(): void
    {
        $doctor = $this->doctor();
        $container = self::getContainer();
        $health = new HealthService(
            new MessengerMonitor(new ServiceLocator([])),
            new UpdateChecker(null, null, new ArrayAdapter()),
            $this->createStub(RequestRepository::class),
            $container->get(RequestService::class),
            (new SchemaBuilder())->build(),
            new Registry(),
            __DIR__,
            __DIR__,
            doctor: $doctor,
        );
        $method = new \ReflectionMethod($health, 'runChecks');
        $checks = $method->invoke($health);
        self::assertSame(\array_keys($this->checks($doctor)), \array_column($checks, 'name'));
        self::assertContains(HealthResult::WARN, \array_column($checks, 'status'));
        self::assertSame([], $method->invoke($health, false));
    }

    #[Test]
    public function reportsStaticCrawlerOverridesAndKeepsLegacySitesUnchanged(): void
    {
        $dir = __DIR__ . '/../App/var/itk-doctor-locales-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/public', 0o775, true);
        try {
            foreach (['robots.txt', 'llms.txt', 'sitemap.xml'] as $file) {
                \file_put_contents($dir . '/public/' . $file, 'manual');
            }
            $check = $this->checks($this->doctor(dir: $dir))[I18n::t('ops.doctor_name_crawler_overrides')];
            self::assertSame(HealthResult::WARN, $check->status);
            foreach (['robots.txt', 'llms.txt', 'sitemap.xml'] as $file) {
                self::assertStringContainsString($file, $check->message);
            }
        } finally {
            foreach (['robots.txt', 'llms.txt', 'sitemap.xml'] as $file) {
                \unlink($dir . '/public/' . $file);
            }
            \rmdir($dir . '/public');
            \rmdir($dir);
        }
        self::assertSame([], $this->doctor(new ContentLocales('ru'))->localeChecks());
        self::assertSame([], $this->doctor(new ContentLocales('ru', ['ru' => ['name' => 'Russian']]))->localeChecks());
    }
}

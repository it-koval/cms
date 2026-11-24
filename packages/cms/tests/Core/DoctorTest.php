<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Analytics\AnalyticsAsset;
use Itk\Cms\Command\DoctorCommand;
use Itk\Cms\Content\PageService;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Health\Doctor;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\HealthService;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\OffHealthCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/** itk:doctor: проверка сайта одним проходом; --ci возвращает 1, если что-то не в порядке. */
final class DoctorTest extends KernelTestCase
{
    private ?string $homeId = null;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        if (null !== $this->homeId) { // the home page of the test database is a draft again, as itk:install made it
            self::getContainer()->get(Connection::class)->executeStatement("UPDATE itk_page_translation SET status = 'draft', published = NULL, published_url = NULL, published_at = NULL WHERE page_id = ?", [$this->homeId]);
        }
        parent::tearDown();
    }

    /** @return list<array{group: string, name: string, status: string, message: string}> */
    private function lines(): array
    {
        return self::getContainer()->get(Doctor::class)->run();
    }

    /**
     * @param list<array{group: string, name: string, status: string, message: string}> $lines
     *
     * @return array{group: string, name: string, status: string, message: string}
     */
    private function line(array $lines, string $name): array
    {
        foreach ($lines as $l) {
            if ($l['name'] === $name) {
                return $l;
            }
        }
        self::fail('no line «' . $name . '» in: ' . \implode(', ', \array_column($lines, 'name')));
    }

    private function publishHome(): void
    {
        $home = self::getContainer()->get(PageRepository::class)->findOneBy(['template' => 'home']);
        self::assertNotNull($home, 'itk:install makes the home page');
        $this->homeId = $home->id;
        self::getContainer()->get(PageService::class)->publish($home->id);
    }

    #[Test]
    public function aDraftHomePageIsAFail(): void
    {
        self::bootKernel();

        $home = $this->line($this->lines(), 'Главная страница');
        self::assertSame(HealthResult::FAIL, $home['status']);
        self::assertStringContainsString('не опубликована', $home['message']);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function webpSupport(): iterable
    {
        yield 'WebP writer' => [true, HealthResult::OK];
        yield 'no WebP writer' => [false, HealthResult::FAIL];
    }

    #[Test]
    #[DataProvider('webpSupport')]
    public function siteHealthAndDoctorUseTheSameWebpResult(bool $supported, string $status): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(ImageVariants::class, new ImageVariants('', '/uploads', [375], 80, 1000000, gdWebp: $supported));

        $checks = \array_column($container->get(HealthService::class)->report()['checks'], null, 'name');
        $lines = $this->lines();
        $line = $this->line($lines, 'WebP');
        self::assertSame($status, $checks['WebP']['status']);
        self::assertSame(['name' => 'WebP', 'status' => $line['status'], 'message' => $line['message']], $checks['WebP']);
        self::assertSame(1, \count(\array_filter($lines, static fn (array $line): bool => 'WebP' === $line['name'])));
    }

    #[Test]
    public function siteHealthAndDoctorReportTheSameLegacyTwigCall(): void
    {
        self::bootKernel();
        $relative = 'templates/legacy-twig-' . \bin2hex(\random_bytes(6)) . '.twig';
        $path = self::$kernel->getProjectDir() . '/' . $relative;
        \file_put_contents($path, "heading\n{{ render_sections(page) }}");

        try {
            $name = 'Устаревшие Twig-функции';
            $checks = \array_column(self::getContainer()->get(HealthService::class)->report()['checks'], null, 'name');
            self::assertSame(HealthResult::WARN, $checks[$name]['status']);
            self::assertStringContainsString($relative . ':2 (render_sections)', $checks[$name]['message']);

            $tester = new CommandTester((new Application(self::$kernel))->find('itk:doctor'));
            $tester->execute([]);
            self::assertStringContainsString($name . ': ' . $checks[$name]['message'], $tester->getDisplay());
        } finally {
            \unlink($path);
        }
    }

    #[Test]
    public function aSiteInOrderHasNoFailLines(): void
    {
        self::bootKernel();
        $this->publishHome();

        $lines = $this->lines();
        $fails = \array_filter($lines, static fn (array $l): bool => HealthResult::FAIL === $l['status']);
        self::assertSame([], \array_values($fails), \json_encode($fails, \JSON_UNESCAPED_UNICODE));
        foreach (['Расширения PHP', 'WebP', 'AVIF', 'APP_SECRET', 'Папка для загрузок', 'База данных', 'Адрес сайта', 'Миграции', 'Главная страница', 'Файрвол админки', 'Очередь и воркер', 'Админка: сборка', 'Загрузчик аналитики', 'Схема сайта'] as $name) {
            $this->line($lines, $name);
        }
        self::assertSame(HealthResult::OK, $this->line($lines, 'Миграции')['status']);
        self::assertSame(HealthResult::OK, $this->line($lines, 'Файрвол админки')['status']);
        self::assertStringContainsString('assets:install', $this->line($lines, 'Админка: сборка')['message'], 'the build is whole, the test site has not installed the assets: a warning, not a fail');
        self::assertSame(HealthResult::OK, $this->line($lines, 'Расширения PHP')['status']);
        self::assertSame(HealthResult::OK, $this->line($lines, 'Главная страница')['status']);
        self::assertContains('Состояние сайта', \array_column($lines, 'group'), 'HealthService::report() is part of the answer');
    }

    #[Test]
    public function theCommandPrintsLinesAndExitsWithOneOnlyForCi(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:doctor'));

        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode(), 'without --ci the command only tells');
        self::assertStringContainsString('Главная страница', $tester->getDisplay());
        self::assertStringContainsString('Итого', $tester->getDisplay());
        self::assertStringContainsString('Расширения PHP', $tester->getDisplay());

        $tester->execute(['--ci' => true]);
        self::assertSame(1, $tester->getStatusCode(), 'the draft home page is a fail');

        $this->publishHome();
        $tester->execute(['--ci' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    #[Test]
    public function aModuleThatIsOffIsPrintedWithItsOwnMarkAndNeverFailsCi(): void
    {
        self::bootKernel();
        self::getContainer()->get(Registry::class)->healthChecks(OffHealthCheck::class);
        $this->publishHome();
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:doctor'));

        $tester->execute(['--ci' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/ выкл\s+Test выключенный модуль: Не подключён/u', $tester->getDisplay());
        self::assertMatchesRegularExpression('/выключено \d+/u', $tester->getDisplay());
        self::assertSame(HealthResult::OFF, $this->line($this->lines(), 'Test выключенный модуль')['status']);
    }

    #[Test]
    public function aBrokenSchemaIsAFailAndDoesNotStopTheDoctor(): void
    {
        self::bootKernel(['itk' => ['schema_file' => __DIR__ . '/Fixture/schema_broken.php']]);

        $lines = $this->lines();

        $schema = $this->line($lines, 'Схема сайта');
        self::assertSame(HealthResult::FAIL, $schema['status']);
        self::assertStringContainsString('ключ поля «title» повторяется', $schema['message']);
        self::assertSame(HealthResult::OK, $this->line($lines, 'Миграции')['status'], 'the other checks still run');
        self::assertContains('Окружение', \array_column($lines, 'group'));
    }

    #[Test]
    public function aWrongPublicUrlFromTheEnvironmentIsAFail(): void
    {
        $_SERVER['ITK_TEST_PUBLIC_URL'] = $_ENV['ITK_TEST_PUBLIC_URL'] = 'example.org';
        try {
            self::bootKernel(['itk' => ['public_url' => '%env(ITK_TEST_PUBLIC_URL)%']]);

            $line = $this->line($this->lines(), 'Адрес сайта');
            self::assertSame(HealthResult::FAIL, $line['status']);
            self::assertStringContainsString('itk_cms.public_url', $line['message']);
        } finally {
            unset($_SERVER['ITK_TEST_PUBLIC_URL'], $_ENV['ITK_TEST_PUBLIC_URL']);
        }
    }

    #[Test]
    public function anAdminMovedWithoutItsFirewallIsAFail(): void
    {
        self::bootKernel(['itk' => ['admin_path' => '/elsewhere']]); // the firewall of the test site still guards ^/admin

        $line = $this->line($this->lines(), 'Файрвол админки');

        self::assertSame(HealthResult::FAIL, $line['status']);
        self::assertStringContainsString('/elsewhere', $line['message']);
        self::assertStringContainsString('security.yaml', $line['message']);
    }

    #[Test]
    public function theAnalyticsLoaderNeedsACurrentInstalledCopy(): void
    {
        $dir = \dirname(__DIR__) . '/App/doctor-analytics-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, recursive: true);
        $installed = $dir . '/analytics.js';
        $bundle = new AnalyticsAsset();

        try {
            $missing = Doctor::checkAnalyticsLoader($bundle, $installed);
            self::assertSame(HealthResult::WARN, $missing->status);
            self::assertStringContainsString('отсутствует', $missing->message);

            \file_put_contents($installed, 'outdated');
            $outdated = Doctor::checkAnalyticsLoader($bundle, $installed);
            self::assertSame(HealthResult::WARN, $outdated->status);
            self::assertStringContainsString('отличается', $outdated->message);

            \copy(\dirname(__DIR__, 2) . '/public/analytics.js', $installed);
            self::assertSame(HealthResult::OK, Doctor::checkAnalyticsLoader($bundle, $installed)->status);
        } finally {
            if (\is_file($installed)) {
                \unlink($installed);
            }
            \rmdir($dir);
        }
    }

    #[Test]
    public function theAdminBuildNeedsItsManifestAndEveryFileOfIt(): void
    {
        $dir = \sys_get_temp_dir() . '/itk-admin-build-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/.vite', 0o775, true);
        \mkdir($dir . '/assets', 0o775, true);
        try {
            self::assertSame(HealthResult::FAIL, Doctor::checkAdminBuild($dir, $dir)->status, 'no manifest');

            \file_put_contents($dir . '/.vite/manifest.json', \json_encode(['index.html' => ['file' => 'assets/index-a.js', 'isEntry' => true, 'css' => ['assets/index-a.css'], 'imports' => ['_x.js']], '_x.js' => ['file' => 'assets/x-b.js']]));
            \file_put_contents($dir . '/assets/index-a.js', '//');
            $result = Doctor::checkAdminBuild($dir, $dir);
            self::assertSame(HealthResult::FAIL, $result->status);
            self::assertStringContainsString('нет на месте: 2', $result->message);

            \file_put_contents($dir . '/assets/index-a.css', '');
            \file_put_contents($dir . '/assets/x-b.js', '//');
            self::assertSame(HealthResult::OK, Doctor::checkAdminBuild($dir, $dir)->status);
            self::assertSame(HealthResult::WARN, Doctor::checkAdminBuild($dir, $dir . '/not-installed')->status);
        } finally {
            foreach (\array_merge(\glob($dir . '/assets/*') ?: [], \glob($dir . '/.vite/*') ?: []) as $f) {
                \unlink($f);
            }
            \rmdir($dir . '/assets');
            \rmdir($dir . '/.vite');
            \rmdir($dir);
        }
    }

    #[Test]
    public function theCommandIsRegistered(): void
    {
        self::bootKernel();

        $command = (new Application(self::$kernel))->find('itk:doctor');
        self::assertInstanceOf(DoctorCommand::class, $command instanceof LazyCommand ? $command->getCommand() : $command);
    }

    #[Test]
    public function aMessageThatIsNotDueYetIsNotAStuckWorker(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '{}', 'test_doctor', (now() AT TIME ZONE 'UTC') - INTERVAL '1 hour', (now() AT TIME ZONE 'UTC') + INTERVAL '1 hour')");
        try {
            self::assertStringNotContainsString('Воркер не запущен', $this->line($this->lines(), 'Очередь и воркер')['message'], 'it waits for its time, not for a worker');

            $db->executeStatement("UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - INTERVAL '30 minutes' WHERE queue_name = 'test_doctor'");
            $line = $this->line($this->lines(), 'Очередь и воркер');
            self::assertSame(HealthResult::WARN, $line['status']);
            self::assertStringContainsString('Воркер не запущен', $line['message'], 'due half an hour ago and still here: nobody takes it');
        } finally {
            $db->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'test_doctor'");
        }
    }
}

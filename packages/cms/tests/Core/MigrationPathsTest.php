<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * doctrine:migrations:diff and :generate write into the FIRST migrations path. The path of the bundle (vendor) must not be it: the site's own path goes first,
 * the bundle's migrations still run (by date, see MigrationOrderTest).
 */
final class MigrationPathsTest extends KernelTestCase
{
    private string $dir = '';
    /** @var list<string> the migrations of the bundle before the test: a broken order must not leave a new one in vendor */
    private array $bundleMigrations = [];

    protected function setUp(): void
    {
        $this->bundleMigrations = \glob(\dirname(__DIR__, 2) . '/src/Migrations/Version*.php') ?: [];
        $this->dir = \sys_get_temp_dir() . '/itk-site-migrations-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (\array_diff(\glob(\dirname(__DIR__, 2) . '/src/Migrations/Version*.php') ?: [], $this->bundleMigrations) as $stray) {
            \unlink($stray);
        }
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], $options['migrationPaths'] ?? []);
    }

    /** @return array<string, string> namespace => directory, in the order Doctrine uses them */
    private function paths(): array
    {
        return self::getContainer()->get('doctrine.migrations.dependency_factory')->getConfiguration()->getMigrationDirectories();
    }

    #[Test]
    public function theSitePathComesFirstAndTheBundleKeepsItsOwn(): void
    {
        self::bootKernel(['migrationPaths' => ['DoctrineMigrations' => $this->dir]]);

        $paths = $this->paths();
        self::assertSame(['DoctrineMigrations', 'Itk\\Cms\\Migrations'], \array_keys($paths));
        self::assertSame($this->dir, $paths['DoctrineMigrations']);
        self::assertDirectoryExists($paths['Itk\\Cms\\Migrations']);
    }

    #[Test]
    public function aNewMigrationGoesToTheSiteNotToVendor(): void
    {
        $kernel = self::bootKernel(['migrationPaths' => ['DoctrineMigrations' => $this->dir]]);
        $generate = new CommandTester((new Application($kernel))->find('doctrine:migrations:generate'));

        self::assertSame(0, $generate->execute([], ['interactive' => false]), $generate->getDisplay());

        $written = \glob($this->dir . '/Version*.php') ?: [];
        self::assertCount(1, $written, 'the migration is in the directory of the site');
        self::assertStringContainsString('namespace DoctrineMigrations;', (string) \file_get_contents($written[0]));
    }

    #[Test]
    public function aSiteWithSeveralPathsKeepsItsOrder(): void
    {
        $second = $this->dir . '/second';
        \mkdir($second);

        try {
            self::bootKernel(['migrationPaths' => ['Site\\First' => $this->dir, 'Site\\Second' => $second]]);

            self::assertSame(['Site\\First', 'Site\\Second', 'Itk\\Cms\\Migrations'], \array_keys($this->paths()));
        } finally {
            \rmdir($second);
        }
    }

    #[Test]
    public function aSiteWithoutMigrationsOfItsOwnStillRunsTheBundle(): void
    {
        self::bootKernel();

        self::assertSame(['Itk\\Cms\\Migrations'], \array_keys($this->paths()));
    }

    #[Test]
    public function theSiteMayMoveTheBundlePathItself(): void
    {
        self::bootKernel(['migrationPaths' => ['Itk\\Cms\\Migrations' => $this->dir]]);

        self::assertSame($this->dir, $this->paths()['Itk\\Cms\\Migrations'], 'the config of the site wins over the default');
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Health\HealthService;
use Itk\Cms\Health\LivenessProbe;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Health\UploadsHealthCheck;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class OpsF2Test extends KernelTestCase
{
    /** @var list<string> */
    private array $emails = [];
    private ?string $dir = null;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', $options['framework'] ?? [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            foreach ($this->emails as $email) {
                self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM itk_user WHERE email = ?', [$email]);
            }
        }
        if (null !== $this->dir) {
            (new Filesystem())->remove($this->dir);
        }
        parent::tearDown();
    }

    #[Test]
    public function installRejectsNineCharactersAndAcceptsTen(): void
    {
        self::bootKernel();
        $command = (new Application(self::$kernel))->find('itk:install');
        foreach ([9 => 1, 10 => 0] as $length => $status) {
            $email = 'f2-install-' . \bin2hex(\random_bytes(5)) . '@example.test';
            $this->emails[] = $email;
            $tester = new CommandTester($command);
            self::assertSame($status, $tester->execute(['--no-home' => true, '--email' => $email, '--password' => \str_repeat('x', $length)], ['interactive' => false]), $tester->getDisplay());
            self::assertSame(10 === $length, null !== self::getContainer()->get(UserRepository::class)->findOneByEmail($email));
        }
        self::assertStringContainsString('10', $command->getDefinition()->getOption('password')->getDescription());
    }

    #[Test]
    #[DataProvider('consoleLocales')]
    public function requestRecoveryCommandsUseTheConsoleLocale(string $locale): void
    {
        self::bootKernel(['framework' => ['default_locale' => $locale]]);
        $console = new Application(self::$kernel);
        $recover = $console->find('itk:requests:recover');
        $resolve = $console->find('itk:requests:mail:resolve');
        self::assertSame(I18n::t('ops.cmd_requests_recover_description'), $recover->getDescription());
        self::assertSame(I18n::t('ops.cmd_requests_mail_resolve_description'), $resolve->getDescription());
        self::assertSame(I18n::t('ops.requests_mail_resolve_recipient'), $resolve->getDefinition()->getArgument('recipient')->getDescription());
        self::assertSame(I18n::t('ops.requests_mail_resolve_legacy_checked'), $resolve->getDefinition()->getOption('legacy-checked')->getDescription());
        self::assertSame(I18n::t('ops.requests_mail_resolve_help'), $resolve->getHelp());
        $tester = new CommandTester($resolve);
        self::assertSame(2, $tester->execute(['request' => '0', 'recipient' => 'recipient@example.test', 'outcome' => 'sent']));
        self::assertSame(I18n::t('ops.requests_mail_resolve_invalid'), \trim($tester->getDisplay()));
        self::assertSame(2, $tester->execute(['request' => '0', '--legacy-checked' => true]));
        self::assertSame(I18n::t('ops.requests_mail_resolve_legacy_invalid'), \trim($tester->getDisplay()));
        self::assertSame(1, $tester->execute(['request' => '2147483647', '--legacy-checked' => true]));
        self::assertSame(I18n::t('ops.requests_mail_resolve_legacy_missing'), \trim($tester->getDisplay()));
    }

    /** @return iterable<string, array{string}> */
    public static function consoleLocales(): iterable
    {
        foreach (['en', 'ru', 'kk'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[Test]
    public function dottedMessengerTransportIsInstalledAndReportedExactlyOnce(): void
    {
        self::bootKernel(['framework' => ['messenger' => [
            'buses' => ['messenger.bus.default' => null],
            'transports' => ['mail.async' => 'doctrine://default?queue_name=f2_dotted&auto_setup=false'],
        ]]]);
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:install'));
        self::assertSame(0, $tester->execute(['--no-home' => true], ['interactive' => false]), $tester->getDisplay());
        self::assertStringContainsString(\Itk\Cms\I18n\I18n::t('ops.install_queue_done', ['count' => 5]), $tester->getDisplay());
        self::assertSame(['mail.async'], \array_values(\array_filter(\array_column(self::getContainer()->get(MessengerMonitor::class)->queues(), 'name'), static fn (string $name): bool => \str_contains($name, 'mail.async'))));
    }

    #[Test]
    public function uploadsChecksAgreeForAFileBlockingThePath(): void
    {
        $this->dir = \dirname(__DIR__, 2) . '/.phpunit.cache/itk-f2-health-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir, 0775, true);
        \file_put_contents($this->dir . '/blocked', 'file');
        $path = $this->dir . '/blocked/media';
        self::bootKernel(['itk' => ['upload_dir' => $path]]);
        self::assertFalse(self::getContainer()->get(LivenessProbe::class)->isAlive());
        self::assertSame('fail', self::getContainer()->get(UploadsHealthCheck::class)->check()->status);
        $disk = \array_column(self::getContainer()->get(HealthService::class)->report()['disk'], null, 'key');
        self::assertSame('fail', $disk['uploads']['status']);
    }

    #[Test]
    public function uploadsChecksAgreeForAWritableParentAndAnExistingFolder(): void
    {
        $this->dir = \dirname(__DIR__, 2) . '/.phpunit.cache/itk-f2-health-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir, 0775, true);
        $path = $this->dir . '/media/nested';
        self::bootKernel(['itk' => ['upload_dir' => $path]]);
        foreach ([false, true] as $create) {
            if ($create) {
                \mkdir($path, 0775, true);
            }
            self::assertTrue(self::getContainer()->get(LivenessProbe::class)->isAlive());
            self::assertSame('ok', self::getContainer()->get(UploadsHealthCheck::class)->check()->status);
            self::assertSame([], \glob($this->dir . '/.itk-write-*'));
        }
    }

    #[Test]
    public function tableFieldKeysAreValidatedBeforeFilesAndFollowTheSchemaRule(): void
    {
        self::bootKernel();
        $this->dir = \dirname(__DIR__, 2) . '/.phpunit.cache/itk-f2-table-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir, 0775, true);
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $table = 'f2_keys_' . \bin2hex(\random_bytes(5));
        $db->executeStatement('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, city TEXT, region TEXT)');
        try {
            $make = new \Itk\Cms\Generator\MakeContext(new \Itk\Cms\Generator\ProjectLayout($this->dir), new \Itk\Cms\Generator\SkeletonRenderer(), new \Itk\Cms\Generator\FileWriter(), $c->get(\Itk\Cms\Resource\ResourceRegistry::class), $this->dir . '/schema.php');
            $command = new \Itk\Cms\Command\MakeCollectionCommand($make, new \Itk\Cms\Generator\CollectionClassBuilder(new \Itk\Cms\Generator\SkeletonRenderer()), new \Itk\Cms\Generator\CollectionSpecReader(), new \Itk\Cms\Generator\TableReader(), $c->get('doctrine'));
            foreach (['city:bad.key', 'city:same,region:same'] as $map) {
                $tester = new CommandTester($command);
                self::assertSame(2, $tester->execute(['key' => 'f2_table', '--from' => 'table:' . $table, '--map' => $map], ['interactive' => false]), $tester->getDisplay());
                self::assertDirectoryDoesNotExist($this->dir . '/src');
            }
            $tester = new CommandTester($command);
            self::assertSame(0, $tester->execute(['key' => 'f2_table', '--from' => 'table:' . $table, '--map' => 'city:1-city'], ['interactive' => false]), $tester->getDisplay());
            self::assertFileExists($this->dir . '/src/Itk/Collection/F2TableCollection.php');
        } finally {
            $db->executeStatement('DROP TABLE ' . $table);
        }
    }
}

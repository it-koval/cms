<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema as DatabaseSchema;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Field\Text;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Migrations\Version20261124110100;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmtpSettingsNamespaceTest extends KernelTestCase
{
    private Connection $db;
    private DatabaseSchema $migrationSchema;

    protected function setUp(): void
    {
        self::bootKernel();
        $resource = new class('f1_smtp_' . \bin2hex(\random_bytes(6))) extends SettingsResource {
            public function __construct(private readonly string $resourceKey)
            {
            }

            public function key(): string
            {
                return $this->resourceKey;
            }

            public function title(): string
            {
                return 'Site mail fixture';
            }

            public function fields(): iterable
            {
                yield Text::make('Site mail', 'mail');
            }
        };
        self::getContainer()->get(ResourceRegistry::class)->add($resource);
        $this->db = self::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
        foreach (['mail', 'itk:mail'] as $key) {
            foreach (['*', 'en'] as $locale) {
                $this->db->delete('itk_setting', ['setting_key' => $key, 'locale' => $locale]);
            }
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->migrationSchema = $this->db->createSchemaManager()->introspectSchema();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        parent::tearDown();
    }

    #[Test]
    public function smtpAndTheSharedSiteMailFieldCannotOverwriteEachOther(): void
    {
        $smtp = self::getContainer()->get(MailSettings::class);
        $settings = self::getContainer()->get(SettingsService::class);
        $smtp->save(self::smtpFixture());
        $saved = $this->value(MailSettings::KEY);
        $siteMail = 'fixture-' . \bin2hex(\random_bytes(5)) . '@example.test';
        $settings->update(['mail' => $siteMail]);

        self::assertSame($saved, $this->value(MailSettings::KEY), 'saving the site field must preserve the SMTP object');
        self::assertSame($siteMail, $settings->get('mail'));
        self::assertSame('smtp.example.test', $smtp->view()['host']);
        $smtp->save(['fromName' => 'Changed SMTP fixture']);
        self::assertSame($siteMail, $settings->get('mail'));
        self::assertSame($siteMail, $this->value('mail'));
        self::assertArrayNotHasKey('itk:mail', $settings->all());
        self::assertSame('itk:mail', MailSettings::KEY);
    }

    #[Test]
    public function migrationMovesTheKnownSmtpObjectAndLeavesLocalizedSiteValues(): void
    {
        $smtp = self::smtpFixture();
        $this->insert('mail', $smtp);
        $this->insert('mail', 'localized-site@example.test', 'en');
        $this->migrate();

        self::assertFalse($this->rowExists('mail'));
        self::assertEquals($smtp, $this->value('itk:mail'));
        self::assertSame('localized-site@example.test', $this->value('mail', 'en'));
        $this->migrate();
        self::assertEquals($smtp, $this->value('itk:mail'), 'a repeated migration leaves the SMTP object intact');
    }

    #[Test]
    public function migrationPreservesSiteOnlyValuesAndRestoresValuesMovedByAnEarlierNamespaceMigration(): void
    {
        foreach (['site@example.test', 0, false, null, ['one', 'two'], ['address' => 'site@example.test'], ['host' => 'site-owned-object']] as $siteValue) {
            $this->insert('mail', $siteValue);
            $this->migrate();
            self::assertTrue($this->rowExists('mail'));
            self::assertSame($siteValue, $this->value('mail'));
            self::assertFalse($this->rowExists('itk:mail'));

            // State produced by F2's earlier broad namespace migration: the site value was renamed without inspecting its shape.
            $this->db->executeStatement('UPDATE itk_setting SET setting_key = ? WHERE setting_key = ? AND locale = ?', ['itk:mail', 'mail', '*']);
            $this->migrate();
            self::assertTrue($this->rowExists('mail'));
            self::assertSame($siteValue, $this->value('mail'));
            self::assertFalse($this->rowExists('itk:mail'));
            $this->migrate();
            self::assertSame($siteValue, $this->value('mail'));
            $this->db->delete('itk_setting', ['setting_key' => 'mail', 'locale' => '*']);
        }
    }

    #[Test]
    public function migrationNeverOverwritesEitherExistingDestination(): void
    {
        foreach ([['site@example.test', self::smtpFixture()], [self::smtpFixture(), self::smtpFixture('other.example.test')], [self::smtpFixture(), 'previous-site-value']] as [$legacy, $namespaced]) {
            $this->insert('mail', $legacy);
            $this->insert('itk:mail', $namespaced);
            $this->migrate();
            self::assertEquals($legacy, $this->value('mail'));
            self::assertEquals($namespaced, $this->value('itk:mail'));
            foreach (['mail', 'itk:mail'] as $key) {
                $this->db->delete('itk_setting', ['setting_key' => $key, 'locale' => '*']);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function smtpFixture(string $host = 'smtp.example.test'): array
    {
        return ['enabled' => false, 'host' => $host, 'port' => 587, 'encryption' => 'starttls', 'username' => 'fixture', 'password' => '', 'fromAddress' => 'fixture@example.test', 'fromName' => 'SMTP fixture'];
    }

    private function insert(string $key, mixed $value, string $locale = '*'): void
    {
        $this->db->insert('itk_setting', ['setting_key' => $key, 'locale' => $locale, 'setting_value' => \json_encode($value, \JSON_THROW_ON_ERROR)]);
    }

    private function value(string $key, string $locale = '*'): mixed
    {
        return \json_decode((string) $this->db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, $locale]), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function rowExists(string $key): bool
    {
        return false !== $this->db->fetchOne('SELECT setting_key FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, '*']);
    }

    private function migrate(): void
    {
        $migration = new Version20261124110100($this->db, new NullLogger());
        $migration->up($this->migrationSchema);
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}

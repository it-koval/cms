<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Schema\Schema as DatabaseSchema;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Field\Text;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Migrations\Version20261124090000;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

final class SettingsNamespaceRegressionTest extends ResourceKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(ResourceRegistry::class)->add(new class extends SettingsResource {
            public function key(): string
            {
                return 'test_mail_namespace';
            }

            public function title(): string
            {
                return 'Site mail';
            }

            public function fields(): iterable
            {
                yield Text::make('Mail', 'mail');
            }
        });
        $this->prepare();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->rollBack();
        $em->clear();
        parent::tearDown();
    }

    #[Test]
    public function siteMailFieldDoesNotOverwriteSmtpAndLegacyRowsMigrate(): void
    {
        self::assertStringStartsWith('itk:', MailSettings::KEY);
        $mail = self::getContainer()->get(MailSettings::class);
        $mail->save(['enabled' => true, 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'test', 'password' => 'test-only-value', 'fromAddress' => 'site@example.test']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $expected = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']);
        $db->executeStatement('DELETE FROM itk_setting WHERE setting_key = ? AND locale = ?', ['mail', '*']);
        $db->executeStatement('UPDATE itk_setting SET setting_key = ? WHERE setting_key = ? AND locale = ?', ['mail', MailSettings::KEY, '*']);
        $webmaster = ['token' => 'test-only-sealed-marker', 'uin' => 'test-verification'];
        $db->executeStatement('DELETE FROM itk_setting WHERE setting_key IN (?, ?) AND locale = ?', ['yandexWebmaster', WebmasterStore::CONNECTION, '*']);
        $db->insert('itk_setting', ['setting_key' => 'yandexWebmaster', 'locale' => '*', 'setting_value' => \json_encode($webmaster, \JSON_THROW_ON_ERROR)]);
        $migration = new Version20261124090000($db, new NullLogger());
        $migration->up(new DatabaseSchema());
        foreach ($migration->getSql() as $sql) {
            $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        $em->clear();
        self::assertSame($expected, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']));
        $migratedWebmaster = \json_decode($db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [WebmasterStore::CONNECTION, '*']), true, flags: \JSON_THROW_ON_ERROR);
        \ksort($webmaster);
        \ksort($migratedWebmaster);
        self::assertSame($webmaster, $migratedWebmaster);
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->update(['mail' => 'Site field']);
        self::assertSame('Site field', $settings->get('mail'));
        self::assertSame('smtp.example.test', $mail->view()['host']);
        self::assertSame($expected, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']));
        $again = new Version20261124090000($db, new NullLogger());
        $again->up(new DatabaseSchema());
        foreach ($again->getSql() as $sql) {
            $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        self::assertSame('"Site field"', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['mail', '*']));
        self::assertSame($expected, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']));
        self::assertSame([], self::getContainer()->get(Schema::class)->validate());
    }
}

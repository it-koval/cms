<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Schema\Schema as DatabaseSchema;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Field\Text;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\SiteMailer;
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
                yield \Itk\Cms\Field\Toggle::make('Site MAX flag', 'maxOn');
                yield \Itk\Cms\Field\Toggle::make('Site Telegram flag', 'tgOn');
                yield Text::make('Site Telegram route', 'tgRoutes');
                yield Text::make('Site MAX route', 'maxRoutes');
                yield Text::make('Site mail route', 'mailRoutes');
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
    public function siteNotificationNamesCannotChangeDeliveryConfiguration(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement("DELETE FROM itk_revision WHERE resource_kind = 'settings' AND resource_key = 'notifications'");
        foreach (['tgOn', 'maxOn', 'tgRoutes', 'maxRoutes', 'mailRoutes'] as $key) {
            $settings->remove('itk:' . $key);
        }
        foreach (['tgOn', 'maxOn'] as $key) {
            $settings->set($key, false);
        }
        foreach (['tgRoutes', 'maxRoutes', 'mailRoutes'] as $key) {
            $settings->set($key, 'Site text');
        }
        self::assertTrue($settings->isTelegramOn());
        self::assertTrue($settings->isMaxOn());
        self::assertSame([], $settings->chatIdsFor('*'));
        self::assertSame([], $settings->maxChatIdsFor('*'));
        self::assertSame([], $settings->mailRecipientsFor('*'));
        $controller = self::getContainer()->get(\Itk\Cms\Controller\Admin\SettingsController::class);
        $notificationRequest = \Symfony\Component\HttpFoundation\Request::create('/admin/api/settings?scope=notifications', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: \json_encode(['tgOn' => false, 'maxOn' => false, 'tgRoutes' => ['*' => '-100123456'], 'maxRoutes' => ['*' => '-23'], 'mailRoutes' => ['*' => 'notify@example.test']], \JSON_THROW_ON_ERROR));
        self::assertFalse(\json_decode($controller->update($notificationRequest)->getContent(), true)['maxOn']);
        self::assertFalse($settings->isTelegramOn());
        self::assertFalse($settings->isMaxOn());
        self::assertSame(['-100123456'], $settings->chatIdsFor('*'));
        self::assertSame(['-23'], $settings->maxChatIdsFor('*'));
        self::assertSame(['notify@example.test'], $settings->mailRecipientsFor('*'));
        $siteRequest = \Symfony\Component\HttpFoundation\Request::create('/admin/api/settings', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"maxOn":true,"tgRoutes":"Updated site text"}');
        self::assertTrue(\json_decode($controller->update($siteRequest)->getContent(), true)['maxOn']);
        self::assertFalse($settings->isMaxOn(), 'editing the site field keeps delivery off');
        self::assertTrue($settings->get('maxOn'));
        self::assertSame('Updated site text', $settings->get('tgRoutes'));
        self::assertFalse($settings->notificationSettings()['maxOn']);
        self::assertSame(['*' => '-100123456'], $settings->notificationSettings()['tgRoutes']);
        $baseline = self::getContainer()->get(\Itk\Cms\Repository\RevisionRepository::class)->findOneBy(['kind' => 'settings', 'resourceKey' => 'notifications'], ['id' => 'ASC']);
        self::assertNotNull($baseline, 'notification edits have their own baseline despite colliding schema fields');
        self::assertTrue($baseline->snapshot['maxOn']);
        $settings->set('maxOn', false);
        self::getContainer()->get(\Itk\Cms\History\HistoryAdmin::class)->restore($baseline->id);
        self::assertTrue($settings->isMaxOn());
        self::assertFalse($settings->get('maxOn'), 'restoring delivery history preserves the site field');
    }

    #[Test]
    public function migrationKeepsLegacySiteMailTextWhenSmtpIsUnconfigured(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->remove(MailSettings::KEY);
        $value = 'Site contact ' . \bin2hex(\random_bytes(5));
        $settings->set('mail', $value);

        self::migrateSettings();

        self::assertSame($value, $settings->get('mail'));
        self::assertFalse($settings->isSet(MailSettings::KEY));
        self::assertFalse(self::getContainer()->get(SiteMailer::class)->isConfigured());
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
        self::migrateSettings();
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
        self::migrateSettings();
        self::assertSame('"Site field"', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['mail', '*']));
        self::assertSame($expected, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [MailSettings::KEY, '*']));
        self::assertSame([], self::getContainer()->get(Schema::class)->validate());
    }

    private static function migrateSettings(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $migration = new Version20261124090000($db, new NullLogger());
        $migration->up(new DatabaseSchema());
        foreach ($migration->getSql() as $sql) {
            $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        $em->clear();
    }
}

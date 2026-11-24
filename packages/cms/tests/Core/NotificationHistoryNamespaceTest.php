<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Controller\Admin\SettingsController;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\Role;
use Itk\Cms\Field\Toggle;
use Itk\Cms\History\HistoryAdmin;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class NotificationHistoryNamespaceTest extends ResourceKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(ResourceRegistry::class)->add(new class extends SettingsResource {
            public function key(): string
            {
                return 'test_history_forms';
            }

            public function title(): string
            {
                return 'Site forms';
            }

            public function fields(): iterable
            {
                yield Toggle::make('Site forms', 'forms');
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

    /** @return iterable<string, array{string}> */
    public static function writers(): iterable
    {
        yield 'integration update' => ['integration'];
        yield 'unscoped admin API' => ['admin'];
        yield 'scoped admin API' => ['notifications'];
    }

    #[Test]
    #[DataProvider('writers')]
    public function notificationHistoryNeverContainsTheFalsySiteFormsValue(string $writer): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->set('forms', false);
        $settings->set('itk:forms', true);
        $settings->set('itk:maxOn', false);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement("DELETE FROM itk_revision WHERE resource_kind = 'settings' AND resource_id = 'notifications'");
        if ('integration' === $writer) {
            $settings->update(['maxOn' => true]);
        } else {
            $scope = 'notifications' === $writer ? '?scope=notifications' : '';
            self::getContainer()->get(SettingsController::class)->update(Request::create('/admin/api/settings' . $scope, 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{"maxOn":true}'));
        }
        $baseline = self::getContainer()->get(RevisionRepository::class)->findOneBy(['kind' => 'settings', 'resourceKey' => Revision::NOTIFICATION_SETTINGS_KEY], ['id' => 'ASC']);
        self::assertNotNull($baseline);
        self::assertFalse($baseline->snapshot['maxOn']);
        self::assertTrue($baseline->snapshot['forms'], 'notification baseline must read itk:forms');
        $latest = self::getContainer()->get(RevisionRepository::class)->findOneBy(['kind' => 'settings', 'resourceKey' => Revision::NOTIFICATION_SETTINGS_KEY], ['id' => 'DESC']);
        self::assertTrue($latest->snapshot['forms'], 'notification edit snapshot must read itk:forms');
        self::getContainer()->get(HistoryAdmin::class)->restore($baseline->id);
        self::assertTrue($settings->acceptsRequests());
        self::assertFalse($settings->isMaxOn());
        self::assertFalse($settings->get('forms'), 'restoring notification history preserves the site field');
    }

    #[Test]
    public function aLegacyNotificationSnapshotCannotWriteSystemSettingsEvenForTheAdministrator(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->set('itk:forms', true);
        $settings->set('itk:maxOn', false);
        $legacy = $this->legacyNotificationSnapshot();
        $history = self::getContainer()->get(HistoryAdmin::class);

        try {
            $history->restore((int) $legacy->id);
        } catch (AccessDeniedException) {
            // A legacy notification revision is view-only.
        }

        self::assertTrue($settings->acceptsRequests(), 'the old site forms value cannot change itk:forms');
        self::assertFalse($settings->isMaxOn());
        self::assertFalse($history->entry((int) $legacy->id)['canRestore']);
    }

    #[Test]
    public function onlyTheFullAdministratorCanReadLegacyNotificationHistory(): void
    {
        $legacy = $this->legacyNotificationSnapshot();
        $history = self::getContainer()->get(HistoryAdmin::class);
        self::assertSame($legacy->id, $history->entry((int) $legacy->id)['id']);
        self::assertSame(1, $history->entries(Revision::SETTINGS, 'notifications', null)['total']);

        $this->signInNotificationsEditor();
        $list = $history->entries(Revision::SETTINGS, 'notifications', null);
        self::assertSame([], $list['items']);
        self::assertSame(0, $list['total'], 'pagination must not count restricted revisions');
        $this->expectException(AccessDeniedException::class);
        $history->entry((int) $legacy->id);
    }

    #[Test]
    public function newNotificationWritesDoNotMergeWithOrDescribeValuesFromTheLegacySnapshot(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->set('itk:forms', true);
        $settings->set('itk:maxOn', false);
        $this->signInNotificationsEditor();
        $legacy = $this->legacyNotificationSnapshot();
        $settings->updateNotifications(['maxOn' => true]);
        $history = self::getContainer()->get(HistoryAdmin::class);

        $list = $history->entries(Revision::SETTINGS, 'notifications', null, 1);
        self::assertSame(2, $list['total'], 'the trusted stream starts with its own baseline');
        self::assertNotSame($legacy->id, $list['items'][0]['id']);
        self::assertSame('notifications', $list['items'][0]['key'], 'the admin API key stays stable');
        self::assertSame(['maxOn'], \array_column($list['items'][0]['summary'], 'key'));
        self::assertTrue($history->entries(Revision::SETTINGS, 'notifications', null, 1, 1)['items'][0]['baseline']);
        self::assertTrue($legacy->snapshot['maxOn']);
        self::assertFalse($legacy->snapshot['forms']);
    }

    private function legacyNotificationSnapshot(): Revision
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("DELETE FROM itk_revision WHERE resource_kind = 'settings' AND resource_id = 'notifications'");
        $legacy = new Revision(Revision::SETTINGS, 'notifications', 'notifications', self::getContainer()->get(Schema::class)->mainLocale(), Revision::EDIT);
        $legacy->userId = self::getContainer()->get(Access::class)->user()?->id;
        $legacy->snapshot = ['forms' => false, 'maxOn' => true, 'oldSiteField' => 'Legacy value'];
        $em->persist($legacy);
        $em->flush();

        return $legacy;
    }

    private function signInNotificationsEditor(): void
    {
        $role = new Role('test-notification-history-editor', 'Notifications editor');
        $role->perms = ['set:notifications' => ['edit']];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();
        $this->signIn($role->key);
    }
}

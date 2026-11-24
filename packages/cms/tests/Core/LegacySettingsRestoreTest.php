<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\Role;
use Itk\Cms\History\HistoryAdmin;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

final class LegacySettingsRestoreTest extends ResourceKernelTestCase
{
    #[Test]
    public function groupEditorCanRestoreOnlyFieldsStillOwnedByThatGroup(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();
        try {
            $settings = self::getContainer()->get(SettingsService::class);
            $settings->updateNotifications(['forms' => true, 'tgOn' => true, 'maxOn' => true]);
            $settings->update(['testTitle' => 'Current title', 'testOn' => false]);
            $role = new Role('test_legacy_settings_restore', 'Settings editor');
            $role->perms = ['set:test_settings' => ['view', 'edit']];
            $em->persist($role);
            $revision = new Revision(Revision::SETTINGS, 'test_settings', 'test_settings', 'ru', Revision::EDIT);
            $revision->snapshot = ['testTitle' => 'Legacy title', 'forms' => false, 'tgOn' => false, 'maxOn' => false, 'mailRoutes' => ['*' => 'old@example.test']];
            $em->persist($revision);
            $em->flush();
            $this->signIn($role->key);

            self::getContainer()->get(HistoryAdmin::class)->restore($revision->id);

            self::assertSame('Legacy title', $settings->get('testTitle'));
            self::assertTrue($settings->acceptsRequests(), 'a legacy group snapshot cannot disable intake without notification rights');
            self::assertTrue($settings->isTelegramOn());
            self::assertTrue($settings->isMaxOn());
            self::assertSame([], $settings->mailRecipientsFor('*'));
        } finally {
            $em->getConnection()->rollBack();
            $em->clear();
        }
    }
}

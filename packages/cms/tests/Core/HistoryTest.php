<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\MenuArea;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\Revision;
use Itk\Cms\History\HistoryAdmin;
use Itk\Cms\History\HistoryService;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** История изменений: запись в сервисах, склейка автосохранений, возврат версии, корзина, чистка. */
final class HistoryTest extends ResourceKernelTestCase
{
    private HistoryAdmin $admin;
    private RevisionRepository $revisions;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $menuKeys = [];

    protected function prepare(): void
    {
        parent::prepare();
        $this->admin = self::getContainer()->get(HistoryAdmin::class);
        $this->revisions = self::getContainer()->get(RevisionRepository::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function clean(): void
    {
        $this->signIn('admin');
        foreach ($this->menuKeys as $key) {
            $area = $this->em->find(MenuArea::class, $key);
            if (null !== $area) {
                $this->em->remove($area);
            }
        }
        $this->em->flush();
        $ids = $this->pageIds;
        parent::clean();
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k OR r.resourceId IN (:ids) OR r.resourceId IN (:menus)')
            ->setParameter('k', 'test%')->setParameter('ids', [] === $ids ? ['-'] : $ids)->setParameter('menus', [] === $this->menuKeys ? ['-'] : $this->menuKeys)->execute();
    }

    /** @return list<Revision> oldest first */
    private function log(string $kind, string $id, string $locale = 'ru'): array
    {
        $this->em->clear();

        return \array_reverse($this->revisions->history($kind, $id, $locale, 500));
    }

    private function last(string $kind, string $id): Revision
    {
        $log = $this->log($kind, $id);

        return $log[\count($log) - 1];
    }

    /** @return list<string> */
    private function actions(string $kind, string $id): array
    {
        return \array_map(static fn (Revision $r): string => $r->action, $this->log($kind, $id));
    }

    /** Pushes the newest entry of an object into the past, so the next edit is not merged with it. */
    private function age(string $kind, string $id, int $minutes): void
    {
        $this->em->createQuery('UPDATE Itk\Cms\Entity\Revision r SET r.updatedAt = :d WHERE r.kind = :k AND r.resourceId = :i')
            ->setParameter('d', new \DateTimeImmutable('-' . $minutes . ' minutes'))->setParameter('k', $kind)->setParameter('i', $id)->execute();
        $this->em->clear(); // the entries already loaded would keep their old time
    }

    #[Test]
    public function aPageIsRecordedFromItsBirthAndTheAutosavesOfOnePersonMakeOneEntry(): void
    {
        $page = $this->page('default', 'Test история', '/test-hist-1');
        $id = $page['id'];

        $this->pages->saveDraft($id, null, ['title' => 'Test история 2']);
        $this->pages->saveDraft($id, null, ['title' => 'Test история 3']);
        $this->pages->saveDraft($id, null, ['title' => 'Test история 4']);

        $log = $this->log('page', $id);
        self::assertSame(['create', 'edit'], \array_map(static fn (Revision $r): string => $r->action, $log), 'три автосохранения подряд — одна запись');
        self::assertSame('Test история 4', $log[1]->title);
        self::assertSame('Test история 4', $log[1]->snapshot['draft']['title']);
        self::assertSame('Test история', $log[0]->snapshot['draft']['title'], 'первая запись хранит версию при рождении');
        self::assertSame('meta', $log[1]->summary[0]['kind']);
        self::assertSame('Test история 4', $log[1]->summary[0]['val']);
        self::assertSame('Test история', $log[1]->summary[0]['old'], 'изменения считаются от записи перед этой');
    }

    #[Test]
    public function aSaveThatChangesNothingLeavesNoEntryAndPuttingEverythingBackRemovesTheEntry(): void
    {
        $id = $this->page('default', 'Test без правок', '/test-hist-2')['id'];

        $this->pages->saveDraft($id, null, ['title' => 'Test без правок']);
        self::assertSame(['create'], $this->actions('page', $id));

        $this->pages->saveDraft($id, null, ['title' => 'Test другое']);
        self::assertSame(['create', 'edit'], $this->actions('page', $id));
        $this->pages->saveDraft($id, null, ['title' => 'Test без правок']);
        self::assertSame(['create'], $this->actions('page', $id), 'человек вернул всё как было: записи об этом нет');
    }

    #[Test]
    public function anotherPersonOrALongPauseStartsANewEntry(): void
    {
        $id = $this->page('default', 'Test двое', '/test-hist-3')['id'];
        $this->pages->saveDraft($id, null, ['title' => 'Test двое 1']);

        $this->signIn('editor');
        $this->pages->saveDraft($id, null, ['title' => 'Test двое 2']);
        self::assertSame(['create', 'edit', 'edit'], $this->actions('page', $id), 'правка другого человека — отдельная запись');
        $log = $this->log('page', $id);
        self::assertSame('admin', $log[1]->userName);
        self::assertSame('editor', $log[2]->userName);

        $this->age('page', $id, 11);
        $this->pages->saveDraft($id, null, ['title' => 'Test двое 3']);
        self::assertSame(['create', 'edit', 'edit', 'edit'], $this->actions('page', $id), 'через 11 минут тот же человек пишет новую запись');
    }

    #[Test]
    public function publishUnpublishAndRevertAreEntriesOfTheirOwn(): void
    {
        $id = $this->page('default', 'Test публикация', '/test-hist-4')['id'];

        $this->pages->publish($id);
        $this->pages->saveDraft($id, null, ['title' => 'Test публикация 2']);
        $this->pages->revert($id);
        $this->pages->unpublish($id);

        self::assertSame(['create', 'publish', 'edit', 'revert', 'unpublish'], $this->actions('page', $id));
        $log = $this->log('page', $id);
        self::assertSame('published', $log[1]->snapshot['status']);
        self::assertSame('draft', $log[4]->snapshot['status']);
        self::assertSame('revert', $log[3]->summary[0]['kind'], 'у записи есть строка о том, что произошло со всей страницей');
        self::assertSame('Test публикация', $log[3]->snapshot['draft']['title']);
    }

    #[Test]
    public function aPageThatLivedBeforeTheHistoryGetsABaselineBeforeItsFirstChange(): void
    {
        $id = $this->page('default', 'Test старая', '/test-hist-5')['id'];
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId = :i')->setParameter('i', $id)->execute();

        $this->pages->saveDraft($id, null, ['title' => 'Test старая, правка']);

        $log = $this->log('page', $id);
        self::assertSame(['edit', 'edit'], \array_map(static fn (Revision $r): string => $r->action, $log));
        self::assertTrue($log[0]->isBaseline());
        self::assertSame('Test старая', $log[0]->snapshot['draft']['title']);
        self::assertFalse($log[1]->isBaseline());
        $entries = $this->admin->entries('page', $id, null);
        self::assertTrue($entries['items'][1]['baseline']);
    }

    #[Test]
    public function aVersionComesBackAsADraftAndTheReturnIsRecorded(): void
    {
        $id = $this->page('default', 'Test A', '/test-hist-6')['id'];
        $this->pages->publish($id);
        $this->pages->saveDraft($id, null, ['title' => 'Test B']);
        $this->signIn('editor');
        $this->pages->saveDraft($id, null, ['title' => 'Test C']);
        $this->signIn('admin');

        $b = $this->log('page', $id)[2];
        self::assertSame('Test B', $b->snapshot['draft']['title']);

        $entry = $this->admin->entry($b->id);
        self::assertFalse($entry['current']);
        self::assertSame('Test B', $entry['against'][0]['val']);
        self::assertSame('Test C', $entry['against'][0]['old'], 'видно, что изменится при возврате');

        $done = $this->admin->restore($b->id);
        self::assertSame('version', $done['restored']);

        $draft = $this->pages->getDraft($id)['page'];
        self::assertSame('Test B', $draft['title']);
        self::assertSame('published', $draft['status'], 'то, что на сайте, не тронуто');
        self::assertSame('Test A', $this->pages->getPublished($id)['title']);
        $last = $this->last('page', $id);
        self::assertSame('restore', $last->action);
        self::assertSame('Test B', $last->snapshot['draft']['title']);
        self::assertSame('restore', $last->summary[0]['kind']);

        $again = $this->admin->entry($last->id);
        self::assertTrue($again['current']);
    }

    #[Test]
    public function aDeletedPageGoesToTheTrashAndComesBackWithAllItsVersions(): void
    {
        $id = $this->page('default', 'Test корзина', '/test-hist-7')['id'];
        $this->pages->publish($id);
        $this->pages->remove($id);

        self::assertNull($this->em->find(Page::class, $id));
        $log = $this->log('page', $id);
        self::assertSame('delete', $log[\count($log) - 1]->action);

        $trash = \array_values(\array_filter($this->admin->trash('page')['items'], static fn (array $r): bool => 'Test корзина' === $r['title']));
        self::assertCount(1, $trash);
        self::assertTrue($trash[0]['wasLive']);
        self::assertTrue($trash[0]['restorable']);
        self::assertSame(['ru'], $trash[0]['locales']);

        $done = $this->admin->restore($trash[0]['id']);
        self::assertSame('trash', $done['restored']);

        $draft = $this->pages->getDraft($id);
        self::assertSame('Test корзина', $draft['page']['title']);
        self::assertSame('draft', $draft['page']['status'], 'вернулась черновиком');
        $this->em->clear();
        self::assertSame('Test корзина', $this->em->find(Page::class, $id)->translation('ru')->published['title'], 'опубликованная копия сохранилась для повторной публикации');
        self::assertSame('restore', $this->last('page', $id)->action);
        self::assertSame([], \array_filter($this->admin->trash('page')['items'], static fn (array $r): bool => 'Test корзина' === $r['title']));

        $this->expectException(ContentException::class);
        $this->admin->restore($trash[0]['id']);
    }

    #[Test]
    public function aPageWhoseAddressWasTakenCannotComeBack(): void
    {
        $id = $this->page('default', 'Test адрес', '/test-hist-8')['id'];
        $this->pages->remove($id);
        $this->page('default', 'Test адрес занят', '/test-hist-8');
        $trash = \array_values(\array_filter($this->admin->trash('page')['items'], static fn (array $r): bool => 'Test адрес' === $r['title']));

        try {
            $this->admin->restore($trash[0]['id']);
            self::fail('адрес занят');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
        }
        self::assertCount(1, \array_filter($this->admin->trash('page')['items'], static fn (array $r): bool => 'Test адрес' === $r['title']), 'из корзины не пропала');
    }

    #[Test]
    public function recordsAreRecordedRestoredAndComeBackFromTheTrash(): void
    {
        $t = $this->trip('Test поездка', ['seats' => 5]);
        $id = $t['id'];
        $this->collections->update('test_trips', $id, ['seats' => 7]);
        $this->collections->publish('test_trips', $id);
        $this->collections->update('test_trips', $id, ['title' => 'Test поездка 2']);
        $this->collections->revert('test_trips', $id);
        $this->collections->unpublish('test_trips', $id);

        self::assertSame(['create', 'edit', 'publish', 'edit', 'revert', 'unpublish'], $this->actions('item', $id));

        $first = $this->log('item', $id)[1];
        self::assertSame(7, $first->snapshot['draft']['seats']);
        $this->collections->update('test_trips', $id, ['seats' => 9]);
        $this->admin->restore($first->id);
        self::assertSame(7, $this->collections->get('test_trips', $id)['item']['seats']);
        self::assertSame('restore', $this->last('item', $id)->action);

        $this->collections->remove('test_trips', $id);
        self::assertSame('delete', $this->last('item', $id)->action);
        $row = \array_values(\array_filter($this->admin->trash('item', 'test_trips')['items'], static fn (array $r): bool => $r['resourceId'] === $id));
        self::assertCount(1, $row);
        self::assertSame('Тестовые поездки', $row[0]['keyTitle']);

        $this->admin->restore($row[0]['id']);
        $back = $this->collections->get('test_trips', $id);
        self::assertSame('Test поездка', $back['item']['title']);
        self::assertSame('draft', $back['item']['status']);
        self::assertSame('trash', $this->last('item', $id)->summary[0]['kind']);
    }

    #[Test]
    public function aPersonDoesNotSeeValuesOfFieldsHeMayNotSee(): void
    {
        $id = $this->trip('Test секрет', ['secret' => 'старый'])['id'];
        $this->collections->update('test_trips', $id, ['secret' => 'новый', 'seats' => 3]);

        $admin = $this->admin->entries('item', $id, null)['items'][0];
        self::assertContains('Секрет', \array_column($admin['summary'], 'label'), 'администратор видит всё');

        $this->signIn('editor');
        $editor = $this->admin->entries('item', $id, null)['items'][0];
        self::assertNotContains('Секрет', \array_column($editor['summary'], 'label'));
        self::assertContains('Мест', \array_column($editor['summary'], 'label'));
        self::assertStringNotContainsString('новый', (string) \json_encode($editor, \JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function rightsDecideWhoReadsAndWhoReturns(): void
    {
        $id = $this->page('default', 'Test права', '/test-hist-9')['id'];
        $this->pages->saveDraft($id, null, ['title' => 'Test права 2']);
        $entry = $this->log('page', $id)[0];

        $this->signIn('requests');
        foreach ([fn () => $this->admin->entries('page', $id, null), fn () => $this->admin->entry($entry->id), fn () => $this->admin->restore($entry->id)] as $call) {
            try {
                $call();
                self::fail('менеджер заявок не должен видеть историю страниц');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->admin->trash()['items'], 'в корзине нет того, что человеку нельзя смотреть');

        $this->signIn('editor');
        self::assertCount(2, $this->admin->entries('page', $id, null)['items']);
        $this->admin->restore($entry->id);
        self::addToAssertionCount(1);
    }

    #[Test]
    public function settingsAndMenusHaveAHistoryToo(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->update(['testSeats' => 5, 'testTitle' => 'Один'], 'ru');
        $settings->update(['testSeats' => 8], 'ru');

        $log = $this->log('settings', 'test_settings');
        self::assertCount(2, $log, 'базовая версия и правка (правки одного человека склеились)');
        self::assertTrue($log[0]->isBaseline());
        self::assertSame(8, $log[1]->snapshot['testSeats']);
        self::assertSame('Тестовые настройки', $log[1]->title);

        $this->admin->restore($log[0]->id);
        self::assertNotSame(8, $settings->get('testSeats'));
        self::assertSame('restore', $this->last('settings', 'test_settings')->action);

        $menus = self::getContainer()->get(MenuService::class);
        $area = $menus->createArea('Test меню');
        $this->menuKeys[] = $area->key;
        $one = ['_id' => 'test1', 'label' => 'Раз', 'link' => ['kind' => 'url', 'url' => '/one']];
        $two = ['_id' => 'test2', 'label' => 'Два', 'link' => ['kind' => 'url', 'url' => '/two']];
        $menus->saveMenus([$area->key => [$one]], 'ru');
        $menus->saveMenus([$area->key => [$one, $two]], 'ru');

        $m = $this->log('menu', $area->key);
        self::assertCount(2, $m, 'базовая версия и одна склеенная правка');
        self::assertSame(['add', 'add'], \array_column($m[1]->summary, 'kind'));
        self::assertSame(['Раз', 'Два'], \array_column($m[1]->summary, 'val'));
        $this->admin->restore($m[0]->id);
        self::assertSame([], $menus->menus('ru')[$area->key], 'вернулась версия до начала истории: меню было пустым');
    }

    #[Test]
    public function pruneKeepsTheNewestEntriesAndDropsTheOld(): void
    {
        $history = self::getContainer()->get(HistoryService::class);
        $id = $this->page('default', 'Test чистка', '/test-hist-10')['id'];
        for ($i = 1; $i <= 4; ++$i) {
            $this->age('page', $id, 11);
            $this->pages->saveDraft($id, null, ['title' => 'Test чистка ' . $i]);
        }
        self::assertCount(5, $this->log('page', $id));

        $dry = $history->prune(2, null, true);
        self::assertGreaterThanOrEqual(3, $dry['extra'], 'чистка общая на всю базу: в тестовой могут лежать и чужие записи');
        self::assertCount(5, $this->log('page', $id), 'пробный запуск ничего не удаляет');

        $history->prune(2);
        $log = $this->log('page', $id);
        self::assertCount(2, $log);
        self::assertSame('Test чистка 4', $log[1]->title, 'остались самые новые');

        $this->em->createQuery('UPDATE Itk\Cms\Entity\Revision r SET r.updatedAt = :d WHERE r.resourceId = :i')->setParameter('d', new \DateTimeImmutable('-400 days'))->setParameter('i', $id)->execute();
        $history->prune(100, 365);
        self::assertCount(1, $this->log('page', $id), 'по возрасту уходит всё, кроме последней записи страницы, которая есть');

        $this->pages->remove($id);
        $this->age('page', $id, 60 * 24 * 400);
        $history->prune(100, 365);
        self::assertSame([], $this->log('page', $id), 'запись об удалении уходит по возрасту: из корзины пропадает то, что пролежало год');
    }

    #[Test]
    public function systemChangesAreRecordedWithoutAPerson(): void
    {
        $id = $this->page('default', 'Test система', '/test-hist-11')['id'];
        self::getContainer()->get('security.token_storage')->setToken(null);

        $this->pages->saveDraft($id, null, ['title' => 'Test система 2']);

        $last = $this->last('page', $id);
        self::assertNull($last->userId);
        self::assertNull($last->userName);
        $this->signIn('admin');
        self::assertNull($this->admin->entries('page', $id, null)['items'][0]['userName']);
    }
}

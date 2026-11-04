<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Event\MassAction;
use Itk\Cms\Event\RecordCreated;
use Itk\Cms\Event\RecordCreating;
use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordDeleting;
use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordPublishing;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\Event\RecordUnpublishing;
use Itk\Cms\Event\RecordUpdated;
use Itk\Cms\Event\RecordUpdating;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use Itk\Cms\Tests\Core\Fixture\TestTemplate;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;

/** Хуки: событие Symfony, потом метод класса; то же для страниц (по шаблону) и записей коллекций, одиночные и массовые. */
final class ResourceHooksTest extends ResourceKernelTestCase
{
    /** @var list<string> */
    private array $events = [];

    protected function prepare(): void
    {
        parent::prepare();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        foreach ([RecordCreating::class, RecordCreated::class, RecordUpdating::class, RecordUpdated::class, RecordDeleting::class, RecordDeleted::class, RecordPublishing::class, RecordPublished::class, RecordUnpublishing::class, RecordUnpublished::class] as $class) {
            $dispatcher->addListener($class, function (RecordEvent $e) use ($class): void {
                if (\in_array($e->resourceKey, ['test_trips', 'test_tpl'], true)) {
                    $name = 'event:' . \substr($class, \strrpos($class, '\\') + 1);
                    TripsCollection::$log[] = $name;
                    TestTemplate::$log[] = $name;
                    $this->events[] = $e->kind . ':' . $e->resourceKey . ':' . $name;
                }
            });
        }
        $dispatcher->addListener(MassAction::class, static function (MassAction $e): void {
            if (\in_array($e->resourceKey, ['test_trips', 'test_tpl'], true)) {
                TripsCollection::$log[] = 'event:Mass:' . $e->action . ':' . $e->phase . ':' . \count($e->records);
                TestTemplate::$log[] = 'event:Mass:' . $e->action . ':' . $e->phase . ':' . \count($e->records);
            }
        });
    }

    #[Test]
    public function creatingAnItemCallsTheEventsThenTheMethodsInOrder(): void
    {
        $item = $this->trip('Алтай', ['seats' => 5]);

        self::assertSame(
            ['event:RecordCreating', 'method:beforeCreating', 'event:RecordCreated', 'method:afterCreated:Алтай'],
            TripsCollection::$log,
        );
        self::assertSame(['item:test_trips:event:RecordCreating', 'item:test_trips:event:RecordCreated'], $this->events);
        self::assertSame('made-by-hook', $item['sys']);
    }

    #[Test]
    public function aListenerChangesTheDataAndTheClassSeesTheChange(): void
    {
        self::getContainer()->get('event_dispatcher')->addListener(RecordCreating::class, static function (RecordCreating $e): void {
            if ('test_trips' === $e->resourceKey) {
                $e->data['note'] = 'listener';
                $e->data['title'] .= ' (слушатель)';
            }
        });
        $item = $this->trip('Байкал');

        self::assertSame('Байкал (слушатель)', $item['title']);
        self::assertSame('listener+class', $item['note'], 'the class hook ran after the listener and saw its data');
    }

    #[Test]
    public function updatingCallsHooksWithTheCurrentRecordAndAFailingHookCancels(): void
    {
        $item = $this->trip('Алтай');
        TripsCollection::$log = [];

        $this->collections->update('test_trips', $item['id'], ['title' => 'Алтай 2']);
        self::assertSame(['event:RecordUpdating', 'method:beforeUpdating', 'event:RecordUpdated', 'method:afterUpdated:Алтай 2'], TripsCollection::$log);

        try {
            $this->collections->update('test_trips', $item['id'], ['title' => 'forbidden']);
            self::fail('a hook may cancel an update');
        } catch (ContentException $e) {
            self::assertSame('Это название нельзя', $e->getMessage());
            self::assertSame(422, $e->status());
        }
        self::assertSame('Алтай 2', $this->collections->get('test_trips', $item['id'])['item']['title']);
    }

    #[Test]
    public function deletingAndPublishingCallTheirHooks(): void
    {
        $item = $this->trip('Алтай');
        TripsCollection::$log = [];
        $this->collections->publish('test_trips', $item['id']);
        self::assertSame(['event:RecordPublishing', 'method:beforePublishing', 'event:RecordPublished', 'method:afterPublished'], TripsCollection::$log);

        TripsCollection::$log = [];
        $this->collections->remove('test_trips', $item['id']);
        self::assertSame(['event:RecordDeleting', 'method:beforeDeleting', 'event:RecordDeleted', 'method:afterDeleted:Алтай'], TripsCollection::$log);
    }

    #[Test]
    public function aBeforeDeletingHookCanKeepTheRecord(): void
    {
        $item = $this->trip('protected');
        try {
            $this->collections->remove('test_trips', $item['id']);
            self::fail('the hook cancels the deletion');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
        }
        self::assertSame('protected', $this->collections->get('test_trips', $item['id'])['item']['title']);
    }

    #[Test]
    public function aMassDeleteAsksOnceForAllThenDeletesEachWithItsOwnHooks(): void
    {
        $a = $this->trip('Один');
        $b = $this->trip('Два');
        TripsCollection::$log = [];

        self::assertSame(2, $this->collections->massRemove('test_trips', [$a['id'], $b['id']]));
        self::assertSame([
            'event:Mass:delete:before:2', 'method:beforeMassDeleting:2',
            'event:RecordDeleting', 'method:beforeDeleting',
            'event:RecordDeleting', 'method:beforeDeleting',
            'event:RecordDeleted', 'method:afterDeleted:Один',
            'event:RecordDeleted', 'method:afterDeleted:Два',
            'event:Mass:delete:after:2', 'method:afterMassDeleted:2',
        ], TripsCollection::$log);
        self::assertSame([], $this->collections->list('test_trips'));
    }

    #[Test]
    public function aMassOperationAnnouncesItsRecordsOnlyAfterTheTransactionIsKept(): void
    {
        $a = $this->trip('Один');
        $b = $this->trip('no-publish');
        TripsCollection::$log = [];
        $this->events = [];

        try {
            $this->collections->massPublish('test_trips', [$a['id'], $b['id']]);
            self::fail('the second record cannot be published, so nothing is');
        } catch (ContentException $e) {
            self::assertSame('Эту запись публиковать нельзя', $e->getMessage());
        }
        self::assertSame(['event:Mass:publish:before:2', 'event:RecordPublishing', 'method:beforePublishing', 'event:RecordPublishing', 'method:beforePublishing'], TripsCollection::$log, 'nothing was announced as done: no afterPublished, no RecordPublished, no afterMassPublished');
        self::assertSame(['item:test_trips:event:RecordPublishing', 'item:test_trips:event:RecordPublishing'], $this->events, 'no listener heard RecordPublished');
    }

    #[Test]
    public function unpublishingOneRecordCallsTheHooksAndEvents(): void
    {
        $item = $this->trip('Алтай');
        $this->collections->publish('test_trips', $item['id']);
        TripsCollection::$log = [];
        $this->events = [];

        $this->collections->unpublish('test_trips', $item['id']);
        self::assertSame(['event:RecordUnpublishing', 'method:beforeUnpublishing', 'event:RecordUnpublished', 'method:afterUnpublished:draft'], TripsCollection::$log);
        self::assertSame(['item:test_trips:event:RecordUnpublishing', 'item:test_trips:event:RecordUnpublished'], $this->events);
    }

    #[Test]
    public function unpublishingAPageCallsTheHooksAndEvents(): void
    {
        $parent = $this->page('default', 'Родитель', '/test-hooks-parent4');
        $page = $this->page('test_tpl', 'Страница', '/test-hooks-4', $parent['id']);
        $this->pages->publish($page['id']);
        TestTemplate::$log = [];
        $this->events = [];

        $this->pages->unpublish($page['id']);
        self::assertSame(['event:RecordUnpublishing', 'method:beforeUnpublishing', 'event:RecordUnpublished', 'method:afterUnpublished'], TestTemplate::$log);
        self::assertSame(['page:test_tpl:event:RecordUnpublishing', 'page:test_tpl:event:RecordUnpublished'], $this->events);
    }

    #[Test]
    public function aMassUnpublishGivesEveryRecordItsOwnSignalAfterTheCommit(): void
    {
        $a = $this->trip('Один');
        $b = $this->trip('Два');
        $this->collections->massPublish('test_trips', [$a['id'], $b['id']]);
        TripsCollection::$log = [];

        self::assertSame(2, $this->collections->massUnpublish('test_trips', [$a['id'], $b['id']]));
        self::assertSame([
            'event:Mass:unpublish:before:2',
            'event:RecordUnpublishing', 'method:beforeUnpublishing',
            'event:RecordUnpublishing', 'method:beforeUnpublishing',
            'event:RecordUnpublished', 'method:afterUnpublished:draft',
            'event:RecordUnpublished', 'method:afterUnpublished:draft',
            'event:Mass:unpublish:after:2',
        ], TripsCollection::$log);
    }

    #[Test]
    public function aMassHookStopsEverythingBeforeAnythingIsDeleted(): void
    {
        $a = $this->trip('Один');
        $b = $this->trip('protected');
        try {
            $this->collections->massRemove('test_trips', [$a['id'], $b['id']]);
            self::fail('beforeMassDeleting cancels the whole operation');
        } catch (ContentException $e) {
            self::assertSame('Среди выбранных есть защищённая запись', $e->getMessage());
        }
        self::assertCount(2, $this->collections->list('test_trips'));
    }

    #[Test]
    public function aMassPublishAndUnpublishWork(): void
    {
        $a = $this->trip('Один');
        $b = $this->trip('Два');
        TripsCollection::$log = [];

        self::assertSame(2, $this->collections->massPublish('test_trips', [$a['id'], $b['id']]));
        self::assertContains('method:afterMassPublished:2', TripsCollection::$log);
        self::assertContains('event:Mass:publish:after:2', TripsCollection::$log);
        self::assertSame('published', $this->collections->get('test_trips', $a['id'])['item']['status']);
        self::assertCount(2, $this->collections->published('test_trips'));

        self::assertSame(2, $this->collections->massUnpublish('test_trips', [$a['id'], $b['id']]));
        self::assertSame('draft', $this->collections->get('test_trips', $a['id'])['item']['status']);
        self::assertSame([], $this->collections->published('test_trips'));
    }

    #[Test]
    public function aPersonCannotWriteReadonlyOrHiddenFieldsButTheSystemAndHooksCan(): void
    {
        $this->signIn('editor');
        $item = $this->trip('Алтай', ['sys' => 'hacked', 'secret' => 'hacked']);
        self::assertSame('made-by-hook', $item['sys'], 'readonly: the person\'s value is ignored, the hook\'s is stored');
        self::assertArrayNotHasKey('secret', $item, 'no «set:users»: the field is not even shown');

        $this->signIn('admin');
        $this->collections->update('test_trips', $item['id'], ['secret' => 'top']);
        self::assertSame('top', $this->collections->get('test_trips', $item['id'])['item']['secret']);

        $this->signIn('editor');
        $this->collections->update('test_trips', $item['id'], ['secret' => 'overwritten', 'title' => 'Новое']);
        self::assertArrayNotHasKey('secret', $this->collections->get('test_trips', $item['id'])['item']);
        self::assertArrayNotHasKey('secret', $this->collections->list('test_trips')[0]);

        self::getContainer()->get('security.token_storage')->setToken(null); // the system
        $this->collections->update('test_trips', $item['id'], ['sys' => 'by-code']);
        $this->signIn('admin');
        $got = $this->collections->get('test_trips', $item['id'])['item'];
        self::assertSame(['by-code', 'top', 'Новое'], [$got['sys'], $got['secret'], $got['title']], 'a hidden field kept its value; the system may write a readonly one');
    }

    #[Test]
    public function onLoadRunsOncePerRequest(): void
    {
        $this->trip('Алтай');
        TripsCollection::$log = [];
        $this->collections->list('test_trips');
        $this->collections->list('test_trips');

        self::assertSame(['method:onLoad'], TripsCollection::$log);
    }

    #[Test]
    public function pagesGetTheSameHooksByTemplate(): void
    {
        $parent = $this->page('default', 'Родитель', '/test-hooks-parent');
        TestTemplate::$log = [];

        $page = $this->page('test_tpl', 'Дочерняя', '/test-hooks-child', $parent['id'], ['sub' => 'x']);
        self::assertSame(['event:RecordCreating', 'method:beforeCreating', 'event:RecordCreated', 'method:afterCreated:test_tpl:Дочерняя'], TestTemplate::$log);
        self::assertSame('x!', $page['fields']['sub'], 'the hook changed the data');

        TestTemplate::$log = [];
        $this->pages->saveDraft($page['id'], null, ['fields' => ['sub' => 'y']]);
        self::assertSame(['event:RecordUpdating', 'method:beforeUpdating', 'event:RecordUpdated', 'method:afterUpdated'], TestTemplate::$log);

        TestTemplate::$log = [];
        $this->pages->publish($page['id']);
        self::assertSame(['event:RecordPublishing', 'method:beforePublishing', 'event:RecordPublished', 'method:afterPublished'], TestTemplate::$log);

        TestTemplate::$log = [];
        $this->pages->remove($page['id']);
        self::assertSame(['event:RecordDeleting', 'method:beforeDeleting', 'event:RecordDeleted', 'method:afterDeleted'], TestTemplate::$log);
        self::assertContains('page:test_tpl:event:RecordDeleted', $this->events);
    }

    #[Test]
    public function pagesHaveMassVariantsToo(): void
    {
        $parent = $this->page('default', 'Родитель', '/test-hooks-parent2');
        $a = $this->page('test_tpl', 'Первая', '/test-hooks-1', $parent['id']);
        $b = $this->page('test_tpl', 'Вторая', '/test-hooks-2', $parent['id']);
        TestTemplate::$log = [];

        self::assertSame(2, $this->pages->massPublish([$a['id'], $b['id']]));
        self::assertContains('event:Mass:publish:before:2', TestTemplate::$log);
        self::assertContains('method:afterMassPublished:2', TestTemplate::$log);

        TestTemplate::$log = [];
        self::assertSame(2, $this->pages->massRemove([$a['id'], $b['id']]));
        self::assertSame('event:Mass:delete:before:2', TestTemplate::$log[0]);
        self::assertSame('method:beforeMassDeleting:2', TestTemplate::$log[1]);
        self::assertSame('event:Mass:delete:after:2', \end(TestTemplate::$log));
    }

    #[Test]
    public function aHiddenTemplateFieldIsNotReadOrWrittenByThoseWhoCannotSeeIt(): void
    {
        $parent = $this->page('default', 'Родитель', '/test-hooks-parent3');
        $page = $this->page('test_tpl', 'Страница', '/test-hooks-3', $parent['id'], ['sub' => 'a', 'secret' => 's1']);
        self::assertSame('s1', $page['fields']['secret']);

        $this->signIn('editor');
        self::assertArrayNotHasKey('secret', (array) $this->pages->get($page['id'])['fields']);
        $this->pages->saveDraft($page['id'], null, ['fields' => ['sub' => 'b', 'secret' => 'hacked']]);

        $this->signIn('admin');
        $fields = (array) $this->pages->get($page['id'])['fields'];
        self::assertSame(['s1', 'b'], [$fields['secret'], $fields['sub']]);
    }
}

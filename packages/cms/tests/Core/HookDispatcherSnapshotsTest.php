<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Resource\HookDispatcher;
use Itk\Cms\Resource\Record;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\RoleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Mutation snapshots survive deferral and are discarded when the write fails, without a kernel or database. */
final class HookDispatcherSnapshotsTest extends TestCase
{
    /** @return iterable<string, array{string, class-string<RecordEvent>}> */
    public static function mutations(): iterable
    {
        yield 'publish' => ['published', RecordPublished::class];
        yield 'unpublish' => ['unpublished', RecordUnpublished::class];
        yield 'delete' => ['deleted', RecordDeleted::class];
    }

    private function hooks(EventDispatcher $events): HookDispatcher
    {
        $schema = new Schema('ru', [], [], [], ['top' => [], 'bottom' => []], [], [], [], [], []);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $access = new Access($security, new RoleResolver($schema, $this->createStub(RoleRepository::class)));

        return new HookDispatcher($events, $schema, $access);
    }

    #[Test]
    #[DataProvider('mutations')]
    public function afterEventsKeepTheCapturedPathsUntilCommit(string $method, string $eventClass): void
    {
        $events = new EventDispatcher();
        $hooks = $this->hooks($events);
        $seen = [];
        $events->addListener($eventClass, static function (RecordEvent $event) use (&$seen): void {
            $seen[] = $event->data;
        });
        $record = new Record('page', 'page', 'default', 'kk', [], 'published');
        $snapshot = ['liveVersions' => [['locale' => 'kk', 'url' => '/kk/test-hook-snapshot', 'seo' => []]]];

        $hooks->deferAfter(static function () use ($hooks, $method, $record, &$snapshot, &$seen): void {
            $hooks->$method($record, $snapshot);
            $snapshot['liveVersions'][0]['url'] = '/kk/test-hook-snapshot-changed';
            self::assertSame([], $seen, 'after events wait until the write succeeds');
        });

        self::assertSame([['liveVersions' => [['locale' => 'kk', 'url' => '/kk/test-hook-snapshot', 'seo' => []]], 'fields' => [], 'meta' => [], 'status' => 'published']], $seen);
    }

    #[Test]
    #[DataProvider('mutations')]
    public function failedWritesDiscardTheirMutationSnapshots(string $method, string $eventClass): void
    {
        $events = new EventDispatcher();
        $hooks = $this->hooks($events);
        $seen = [];
        $events->addListener($eventClass, static function (RecordEvent $event) use (&$seen): void {
            $seen[] = $event->data;
        });
        $record = new Record('page', 'page', 'default', 'kk', [], 'published');
        $failure = new \RuntimeException('Write failed');
        $caught = null;
        try {
            $hooks->deferAfter(static function () use ($hooks, $method, $record, $failure): void {
                $hooks->$method($record, ['liveVersions' => [['locale' => 'kk', 'url' => '/kk/test-hook-snapshot', 'seo' => []]]]);
                throw $failure;
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame($failure, $caught);
        self::assertSame([], $seen);
        $hooks->$method($record);
        self::assertSame([['fields' => [], 'meta' => [], 'status' => 'published']], $seen, 'failure does not prevent subsequent events and the old signature remains valid');
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Event\MassAction;
use Itk\Cms\Event\PageTemplateChanged;
use Itk\Cms\Event\RecordCreated;
use Itk\Cms\Event\RecordCreating;
use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordDeleting;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordPublishing;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\Event\RecordUnpublishing;
use Itk\Cms\Event\RecordUpdated;
use Itk\Cms\Event\RecordUpdating;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tells the site what happens to its records: the Symfony event first (listeners), then the method of the resource class.
 * PageService and CollectionService call it around create / update / delete / publish / unpublish and their mass variants.
 * Inside deferAfter() the `after…` hooks and events wait until the work is done: a mass operation runs them only after its transaction is committed.
 */
final class HookDispatcher
{
    /** @var array<string, true> */
    private array $loaded = [];

    /** @var list<\Closure>|null the `after…` calls waiting for the end of deferAfter() */
    private ?array $queue = null;

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly Schema $schema,
        private readonly Access $access,
    ) {
    }

    /**
     * Runs `$work` (a transaction) and holds back the `after…` hooks and events of the records it touches until it has finished without an error;
     * then they run in order. If `$work` throws, they never run: nothing they would announce has been kept.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function deferAfter(\Closure $work): mixed
    {
        if (null !== $this->queue) {
            return $work();
        }
        $this->queue = [];
        try {
            $result = $work();
            $waiting = $this->queue;
        } finally {
            $this->queue = null;
        }
        foreach ($waiting as $run) {
            $run();
        }

        return $result;
    }

    /** The core reads a section for the first time in this request. */
    public function loaded(string $kind, string $key, string $locale): void
    {
        if (isset($this->loaded[$kind . ':' . $key])) {
            return;
        }
        $this->loaded[$kind . ':' . $key] = true;
        $this->resource($kind, $key)?->onLoad($this->context($kind, $key, $locale));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the data to store
     */
    public function creating(string $kind, string $key, string $locale, array $data, bool $translationOnly = false): array
    {
        $viewer = $this->access->viewer();
        $this->events->dispatch($event = new RecordCreating($key, $kind, null, $data, $viewer, $locale, $translationOnly));

        return $this->array($this->resource($kind, $key)?->beforeCreating($event->data, $this->context($kind, $key, $locale, $translationOnly)) ?? $event->data, 'beforeCreating');
    }

    public function created(Record $record): void
    {
        $this->after(function () use ($record): void {
            $this->events->dispatch(new RecordCreated($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
            $this->resource($record->kind, $record->resourceKey)?->afterCreated($record, $this->contextOf($record));
        });
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the change to apply
     */
    public function updating(Record $record, array $data): array
    {
        $this->events->dispatch($event = new RecordUpdating($record->resourceKey, $record->kind, $record, $data, $this->access->viewer()));

        return $this->array($this->resource($record->kind, $record->resourceKey)?->beforeUpdating($record, $event->data, $this->contextOf($record)) ?? $event->data, 'beforeUpdating');
    }

    public function updated(Record $record): void
    {
        $this->after(function () use ($record): void {
            $this->events->dispatch(new RecordUpdated($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
            $this->resource($record->kind, $record->resourceKey)?->afterUpdated($record, $this->contextOf($record));
        });
    }

    public function templateChanged(Record $record, string $from): void
    {
        $this->after(function () use ($record, $from): void {
            $this->events->dispatch(new PageTemplateChanged($record->resourceKey, $record, $this->access->viewer(), $from));
        });
    }

    public function deleting(Record $record): void
    {
        $this->events->dispatch(new RecordDeleting($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
        $this->resource($record->kind, $record->resourceKey)?->beforeDeleting($record, $this->contextOf($record));
    }

    public function deleted(Record $record): void
    {
        $this->after(function () use ($record): void {
            $this->events->dispatch(new RecordDeleted($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
            $this->resource($record->kind, $record->resourceKey)?->afterDeleted($record, $this->contextOf($record));
        });
    }

    public function publishing(Record $record): void
    {
        $this->events->dispatch(new RecordPublishing($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
        $this->resource($record->kind, $record->resourceKey)?->beforePublishing($record, $this->contextOf($record));
    }

    public function published(Record $record): void
    {
        $this->after(function () use ($record): void {
            $this->events->dispatch(new RecordPublished($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
            $this->resource($record->kind, $record->resourceKey)?->afterPublished($record, $this->contextOf($record));
        });
    }

    public function unpublishing(Record $record): void
    {
        $this->events->dispatch(new RecordUnpublishing($record->resourceKey, $record->kind, $record, [], $this->access->viewer()));
        $this->resource($record->kind, $record->resourceKey)?->beforeUnpublishing($record, $this->contextOf($record));
    }

    /** @param array{url: string, seo: array<string, mixed>}|array{} $previousLiveAddress */
    public function unpublished(Record $record, array $previousLiveAddress = []): void
    {
        $this->after(function () use ($record, $previousLiveAddress): void {
            $this->events->dispatch(new RecordUnpublished($record->resourceKey, $record->kind, $record, $previousLiveAddress, $this->access->viewer()));
            $this->resource($record->kind, $record->resourceKey)?->afterUnpublished($record, $this->contextOf($record));
        });
    }

    /**
     * Before / after an operation on several records.
     *
     * @param 'delete'|'publish'|'unpublish' $action
     * @param 'before'|'after' $phase
     * @param list<Record> $records
     */
    public function mass(string $action, string $phase, string $kind, string $key, string $locale, array $records): void
    {
        $this->events->dispatch(new MassAction($key, $kind, $action, $phase, $records, $this->access->viewer()));
        $resource = $this->resource($kind, $key);
        if (null === $resource) {
            return;
        }
        $ctx = $this->context($kind, $key, $locale);
        match ($action . ':' . $phase) {
            'delete:before' => $resource->beforeMassDeleting($records, $ctx),
            'delete:after' => $resource->afterMassDeleted($records, $ctx),
            'publish:before' => $resource->beforeMassPublishing($records, $ctx),
            'publish:after' => $resource->afterMassPublished($records, $ctx),
            'unpublish:before' => $resource->beforeMassUnpublishing($records, $ctx),
            'unpublish:after' => $resource->afterMassUnpublished($records, $ctx),
            default => throw new \LogicException(\sprintf('Unknown mass action "%s" "%s".', $action, $phase)),
        };
    }

    private function after(\Closure $run): void
    {
        if (null !== $this->queue) {
            $this->queue[] = $run;

            return;
        }
        $run();
    }

    private function resource(string $kind, string $key): CollectionResource|TemplateResource|null
    {
        return 'page' === $kind ? $this->schema->template($key)?->resource() : $this->schema->collection($key)?->resource();
    }

    private function context(string $kind, string $key, string $locale, bool $translationOnly = false): Context
    {
        return new Context($this->access->viewer(), $locale, $kind, $key, $translationOnly);
    }

    private function contextOf(Record $record): Context
    {
        return $this->context($record->kind, $record->resourceKey, $record->locale, $record->translationOnly);
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value, string $hook): array
    {
        if (!\is_array($value)) {
            throw new \UnexpectedValueException(\sprintf('%s() of a resource class must return an array of data.', $hook));
        }

        return $value;
    }
}

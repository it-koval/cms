<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Itk\Cms\Resource\Record;
use Itk\Cms\Security\Viewer;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An operation on several records at once ('delete' | 'publish' | 'unpublish'). `phase` 'before' may throw Itk\Cms\Content\ContentException
 * to stop the whole operation. Every record also goes through its own RecordDeleting / RecordPublished … events.
 */
final class MassAction extends Event
{
    public const BEFORE = 'before';
    public const AFTER = 'after';

    /**
     * @param 'page'|'item' $kind
     * @param 'before'|'after' $phase
     * @param list<Record> $records
     */
    public function __construct(
        public readonly string $resourceKey,
        public readonly string $kind,
        public readonly string $action,
        public readonly string $phase,
        public readonly array $records,
        public readonly Viewer $viewer,
    ) {
    }
}

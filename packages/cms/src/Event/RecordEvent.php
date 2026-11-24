<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Itk\Cms\Resource\Record;
use Itk\Cms\Security\Viewer;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Something happens to a record. Listen to the concrete event (RecordCreating, RecordUpdated …); `resourceKey` is the collection key
 * or, for a page, its template key; `kind` is 'item' (collection record) or 'page'.
 * The events named …ing come BEFORE the change and may throw Itk\Cms\Content\ContentException to cancel it; `data` of RecordCreating
 * and RecordUpdating can be changed — that is what gets stored. The resource class hook of the same moment runs after the listeners.
 * After events carry a snapshot in `data` with `fields`, `meta`, and `status`. A publish event describes the published version.
 * A delete event describes the record as it was before removal. Page events can also carry `liveVersions` with affected live addresses.
 */
abstract class RecordEvent extends Event
{
    /**
     * @param 'page'|'item' $kind
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $resourceKey,
        public readonly string $kind,
        public readonly ?Record $record,
        public array $data,
        public readonly Viewer $viewer,
    ) {
    }
}

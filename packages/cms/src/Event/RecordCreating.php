<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Itk\Cms\Resource\Record;
use Itk\Cms\Security\Viewer;

/** Before a record is created (no `record` yet; `data` is what is going to be stored — change it). */
final class RecordCreating extends RecordEvent
{
    /**
     * @param 'page'|'item' $kind
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $resourceKey,
        string $kind,
        ?Record $record,
        array $data,
        Viewer $viewer,
        public readonly ?string $locale = null,
    ) {
        parent::__construct($resourceKey, $kind, $record, $data, $viewer);
    }
}

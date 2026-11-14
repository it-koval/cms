<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Itk\Cms\Resource\Record;
use Itk\Cms\Security\Viewer;

/**
 * A page got another template. The template is shared by all languages and takes effect at once, published versions included, so this comes
 * besides RecordUpdated (whose `resourceKey` is already the new template). `from` is the previous template key.
 */
final class PageTemplateChanged extends RecordEvent
{
    public function __construct(
        string $resourceKey,
        ?Record $record,
        Viewer $viewer,
        public readonly string $from,
    ) {
        parent::__construct($resourceKey, 'page', $record, [], $viewer);
    }
}

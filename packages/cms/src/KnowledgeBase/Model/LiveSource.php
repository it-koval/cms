<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * A live, included source as SourceReader hands it to the Extractor: the published snapshot only, never the draft.
 *
 * @internal
 */
final readonly class LiveSource
{
    public function __construct(
        public Source $source,
        /**
         * page:  the published snapshot { title, url, seo, sections: [{id, type, hidden, data}], fields }
         * item:  the published field values { key: value }.
         *
         * @var array<string, mixed>
         */
        public array $published,
        /** for the foreign key: the PageTranslation id of a page, null for an item */
        public ?string $pageTranslationId,
    ) {
    }
}

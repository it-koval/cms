<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * What a hit cites: the page or item a chunk came from, as it was when it was indexed.
 * The class is public API: a search hit carries it.
 */
final readonly class Source
{
    public function __construct(
        public SourceKind $kind,
        /** the page id or the item id: ContentReader::page($id, $locale) / the collection's record */
        public string $id,
        public string $locale,
        /** the template key of a page, the collection key of an item */
        public string $resourceKey,
        public string $title,
        /** the published path of this language version (/services/audit), null for items (they have no page). The site adds its own locale prefix. */
        public ?string $url,
    ) {
    }

    public function ref(): SourceRef
    {
        return new SourceRef($this->kind, $this->id, $this->locale);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

use Itk\Cms\KnowledgeBase\Embedding\Space;

/**
 * What a live source's document should be now. Zero chunks is a valid plan (an item whose fields are all skipped): it is stored as a document
 * with chunk_count 0, so it reads Current, not Missing, next time.
 *
 * @internal
 */
final readonly class DocumentPlan
{
    /** @param list<Chunk> $chunks */
    public function __construct(
        public LiveSource $live,
        public Space $space,
        public array $chunks,
        public Basis $basis,
    ) {
    }
}

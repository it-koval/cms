<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * One source as Indexer::plan() sees it without the API: its state, and how many inputs would be sent to the embedder (the rest are reused by hash).
 * The command's --dry-run prints these; the command's default queues the ones that need work.
 *
 * @internal
 */
final readonly class Planned
{
    public function __construct(
        public SourceRef $ref,
        public Freshness $state,
        public ?DocumentPlan $plan,
        /** inputs whose hash is not in the table for this space */
        public int $newInputs,
        /** a guess (characters / 3), for the dry run only */
        public int $estimatedTokens,
    ) {
    }
}

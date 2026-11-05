<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

final readonly class Embeddings
{
    /** @param list<Vector> $vectors one per input, in input order */
    public function __construct(
        public array $vectors,
        /** tokens the provider billed, null when it does not say */
        public ?int $tokens,
    ) {
    }
}

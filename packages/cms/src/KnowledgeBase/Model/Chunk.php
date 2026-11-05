<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * One row of itk_kb_chunk before it has a vector. Made only by Chunker::chunk().
 *
 * @internal
 */
final readonly class Chunk
{
    public function __construct(
        /** 0..n-1, contiguous within the document, in render order */
        public int $ordinal,
        public ?string $sectionId,
        public ?string $sectionType,
        public string $field,
        public ?string $heading,
        /** what a hit shows */
        public string $text,
        /** what is embedded: the context header ("{title}\n{section heading}\n\n") and the text */
        public string $input,
        /** sha256 of $input: the reuse key of its vector in the same space */
        public string $inputHash,
    ) {
    }
}

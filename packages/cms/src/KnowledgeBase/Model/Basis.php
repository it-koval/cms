<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

use Itk\Cms\KnowledgeBase\Embedding\Space;

/**
 * Everything a document's rows depend on: the space, the title and url it cites, and every chunk's origin and input.
 * Derived from the extractor's output, not from the snapshot: a deploy that changes what is extracted (a field made raw(), a section type gone,
 * a template excluded) changes it without an event, and a publish that changes nothing indexable (an image src, a layout variant) keeps it.
 *
 * @internal
 */
final readonly class Basis
{
    private function __construct(public string $value)
    {
    }

    /** @param list<Chunk> $chunks */
    public static function of(Space $space, Source $source, array $chunks): self
    {
        $parts = [$space->id(), $source->title, $source->url ?? ''];
        foreach ($chunks as $chunk) {
            $parts[] = ($chunk->sectionId ?? '') . '|' . $chunk->field . '|' . $chunk->inputHash;
        }

        return new self(\sha1(\implode("\x1f", $parts)));
    }

    public static function fromStored(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * One topical unit of text the Extractor found, in render order: the head of a page, a section, a subheading run of an Article,
 * one row of a Repeater, an item. The Chunker packs blocks into chunks and never merges blocks of two sections.
 *
 * @internal
 */
final readonly class Block
{
    /** @param list<string> $paragraphs plain text, whitespace collapsed, never empty strings */
    public function __construct(
        /** null for the page head and for items */
        public ?string $sectionId,
        public ?string $sectionType,
        /** where the text is, for citing: 'title', 'fields.excerpt', 'text', 'items.2.answer' (relative to the section data or the item) */
        public string $field,
        public ?string $heading,
        public array $paragraphs,
    ) {
    }
}

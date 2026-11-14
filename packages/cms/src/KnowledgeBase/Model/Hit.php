<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * One chunk that matched a query. Public API: a site search, an MCP tool and a chatbot read these fields.
 * Every hit is text an anonymous visitor can read on the live site right now (search joins the live status).
 */
final readonly class Hit
{
    public function __construct(
        /** cosine similarity, 1 = same meaning; what counts as related depends on the model (for text-embedding-3-small about 0.3 and up) */
        public float $score,
        /** the chunk as plain text, without the context header that was embedded with it */
        public string $text,
        /** the subheading, question or section headline the chunk sits under, if any */
        public ?string $heading,
        public Source $source,
        /** the page section the text is in (the `id` of the section in the snapshot, data-sec in the markup); null for the page head and items */
        public ?string $sectionId,
        public ?string $sectionType,
        /** the field it came from: 'title', 'seo.description', 'fields.excerpt', 'text', 'items.2.answer' */
        public string $field,
    ) {
    }
}

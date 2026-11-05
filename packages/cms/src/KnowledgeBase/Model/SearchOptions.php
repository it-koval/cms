<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * Public API: what narrows a KnowledgeSearch::search(). `new SearchOptions()` is the main language, eight hits, every indexed template and collection.
 *
 *   new SearchOptions(locale: 'en', limit: 20)
 *   new SearchOptions(templates: ['blog_article'], collections: [])     pages of one template, no items
 *   new SearchOptions(limit: 6, minScore: 0.35)                         a RAG context
 */
final readonly class SearchOptions
{
    public const MAX_LIMIT = 50;

    /**
     * @param string|null $locale null = the main locale; only chunks of that language version
     * @param int $limit 1..50 hits
     * @param list<string>|null $templates null = pages of every indexed template, [] = no pages
     * @param list<string>|null $collections null = items of every indexed collection, [] = no items
     * @param float $minScore hits under this cosine similarity are left out
     *
     * @throws \InvalidArgumentException a limit out of 1..50 (a developer mistake)
     */
    public function __construct(
        public ?string $locale = null,
        public int $limit = 8,
        public ?array $templates = null,
        public ?array $collections = null,
        public float $minScore = 0.0,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('The limit of a knowledge base search is 1..%d, not %d.', self::MAX_LIMIT, $limit));
        }
    }
}

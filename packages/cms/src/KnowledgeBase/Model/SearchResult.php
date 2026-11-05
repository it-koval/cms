<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/** Public API: what KnowledgeSearch::search() returns. */
final readonly class SearchResult
{
    /** @param list<Hit> $hits best first */
    public function __construct(
        public string $query,
        public string $locale,
        public array $hits,
    ) {
    }

    /**
     * One hit per source (page or item), the best one, in the order of $hits: what a site search lists, with the hit's text as the snippet.
     *
     * @return list<Hit>
     */
    public function bySource(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->hits as $hit) {
            $key = $hit->source->ref()->key();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $hit;
        }

        return $out;
    }
}

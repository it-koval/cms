<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

/**
 * Which vector space an embedding lives in: provider, model and dimensions. Vectors of two spaces are never compared; search filters by the space
 * of the configured embedder, and a change of model makes every document Stale (the space is part of the Basis).
 *
 * The dimension is fixed: itk_kb_chunk.embedding is vector(DIMENSIONS) with an HNSW index (pgvector indexes `vector` up to 2000 dimensions).
 * An embedder must produce exactly DIMENSIONS (OpenAI text-embedding-3-* do, by the `dimensions` request parameter).
 */
final readonly class Space
{
    public const DIMENSIONS = 1536;

    public function __construct(
        /** 'openai' */
        public string $provider,
        /** 'text-embedding-3-small' */
        public string $model,
    ) {
    }

    /** 'openai:text-embedding-3-small:1536': stored in itk_kb_document.space and itk_kb_chunk.space. */
    public function id(): string
    {
        return $this->provider . ':' . $this->model . ':' . self::DIMENSIONS;
    }
}

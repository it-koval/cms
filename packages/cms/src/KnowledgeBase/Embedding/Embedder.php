<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

/**
 * Turns texts into vectors of one space. The bundle's implementation is OpenAiEmbedder; a site may alias this interface to its own
 * (any provider that can produce Space::DIMENSIONS dimensions). Tests alias it to a fake.
 *
 * The implementation splits a long list into requests of its own size; the caller passes all inputs it has.
 */
interface Embedder
{
    /** The space of every vector this embedder returns; part of every document's basis. */
    public function space(): Space;

    /**
     * @param list<string> $texts non-empty strings, each well under the provider's input limit
     *
     * @throws EmbeddingFailed
     */
    public function embed(array $texts, Purpose $purpose): Embeddings;
}

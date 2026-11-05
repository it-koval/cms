<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Itk\Cms\KnowledgeBase\Embedding\Embedder;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Embedding\Embeddings;
use Itk\Cms\KnowledgeBase\Embedding\Purpose;
use Itk\Cms\KnowledgeBase\Embedding\Space;
use Itk\Cms\KnowledgeBase\Embedding\Vector;

/**
 * The embedder of the test site (aliased over OpenAiEmbedder): no network. A hashed bag of words: every lower-cased word adds 1 to dimension
 * crc32(word) % 1536, then the vector is normalized. Texts that share words come out close, so a search test can assert a ranking.
 * Records every call, so a test can assert what was embedded (only changed chunks on a republish) and can make the next call fail.
 */
final class FakeEmbedder implements Embedder
{
    /** @var list<array{texts: list<string>, purpose: Purpose}> every call, in order */
    public array $calls = [];

    public ?EmbeddingFailed $failNext = null;

    /** the model of the space: a test changes it to make every document stale */
    public string $model = 'bag-of-words';

    public function space(): Space
    {
        return new Space('fake', $this->model);
    }

    public function embed(array $texts, Purpose $purpose): Embeddings
    {
        $this->calls[] = ['texts' => $texts, 'purpose' => $purpose];
        if (null !== $this->failNext) {
            $failure = $this->failNext;
            $this->failNext = null;
            throw $failure;
        }
        $vectors = [];
        $tokens = 0;
        foreach ($texts as $text) {
            $values = \array_fill(0, Space::DIMENSIONS, 0.0);
            $words = \preg_split('/[^\p{L}\p{N}]+/u', \mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                ++$values[\crc32($word) % Space::DIMENSIONS];
            }
            $tokens += \count($words);
            $norm = \sqrt(\array_sum(\array_map(static fn (float $v): float => $v * $v, $values))) ?: 1.0;
            $vectors[] = Vector::of(\array_map(static fn (float $v): float => $v / $norm, $values));
        }

        return new Embeddings($vectors, $tokens);
    }

    /** @return list<string> every text embedded as a document so far, in order */
    public function embeddedDocuments(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if (Purpose::Document === $call['purpose']) {
                $out = [...$out, ...$call['texts']];
            }
        }

        return $out;
    }

    /** Between tests; not reset(), which the container would also call between requests. */
    public function forget(): void
    {
        $this->calls = [];
        $this->failNext = null;
        $this->model = 'bag-of-words';
    }
}

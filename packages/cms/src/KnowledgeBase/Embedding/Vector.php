<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Embedding;

/**
 * One embedding: exactly Space::DIMENSIONS finite floats. Built only by an Embedder (parsing its provider's answer) or read back from the table,
 * so a misconfigured provider fails here, at the boundary, not inside an INSERT.
 */
final readonly class Vector
{
    /** @param list<float> $values */
    private function __construct(public array $values)
    {
    }

    /**
     * @param array<mixed> $values
     *
     * @throws EmbeddingFailed (permanent) when the count is wrong or a value is not a finite number
     */
    public static function of(array $values): self
    {
        if (Space::DIMENSIONS !== \count($values)) {
            throw EmbeddingFailed::permanent(\sprintf('The embedder returned a vector of %d dimensions, not %d.', \count($values), Space::DIMENSIONS));
        }
        $out = [];
        foreach ($values as $v) {
            if (!\is_int($v) && !\is_float($v) || !\is_finite((float) $v)) {
                throw EmbeddingFailed::permanent('The embedder returned a vector with a value that is not a finite number.');
            }
            $out[] = (float) $v;
        }

        return new self($out);
    }

    /** pgvector's text form '[0.1,0.2,…]', bound as a string parameter and cast with ::vector. */
    public function toSql(): string
    {
        return '[' . \implode(',', \array_map(static fn (float $v): string => \rtrim(\rtrim(\sprintf('%.8F', $v), '0'), '.'), $this->values)) . ']';
    }

    /** The column read back ('[0.1,0.2,…]'); trusted, it is our own column. */
    public static function fromSql(string $text): self
    {
        return new self(\array_map('floatval', \explode(',', \trim($text, "[] \n"))));
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One embedded piece of a KnowledgeDocument, rewritten whole with it. Mapped for the schema only; written by KnowledgeBase\Indexer, read by
 * KnowledgeBase\KnowledgeSearch, both with SQL (the `<=>` operator has no DQL).
 *
 * kind, locale, resource_key and space repeat the document's on purpose: they never change for a document id, are written in the same statement,
 * and let the HNSW scan filter without a join.
 *
 * The index itk_kb_chunk_embedding_idx is `USING hnsw (embedding vector_cosine_ops)` in the migration; the mapping names the same index on the same
 * column so that doctrine:schema:validate sees no difference (DBAL does not compare the access method; DoctrineSchemaTest proves it).
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'itk_kb_chunk')]
#[ORM\Index(name: 'itk_kb_chunk_embedding_idx', columns: ['embedding'])]
#[ORM\Index(name: 'itk_kb_chunk_reuse_idx', columns: ['space', 'input_hash'])]
#[ORM\Index(name: 'itk_kb_chunk_filter_idx', columns: ['space', 'locale', 'kind', 'resource_key'])]
class KnowledgeChunk
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: KnowledgeDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public KnowledgeDocument $document;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT)]
    public int $ordinal;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $kind;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale;

    #[ORM\Column(name: 'resource_key', type: Types::STRING, length: 64)]
    public string $resourceKey;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $space;

    #[ORM\Column(name: 'section_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $sectionId = null;

    #[ORM\Column(name: 'section_type', type: Types::STRING, length: 64, nullable: true)]
    public ?string $sectionType = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $field;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $heading = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $text;

    /** sha256 of the exact string embedded: the reuse key across documents of the same space */
    #[ORM\Column(name: 'input_hash', type: Types::STRING, length: 64)]
    public string $inputHash;

    /** pgvector vector(1536), as its text form '[…]' (Doctrine\VectorType) */
    #[ORM\Column(type: 'vector')]
    public string $embedding;
}

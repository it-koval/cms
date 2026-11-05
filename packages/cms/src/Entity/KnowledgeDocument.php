<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One indexed source version of the knowledge base: a page version or an item version, in one language. Mapped so that the ORM knows the table
 * (doctrine:schema:validate, the migrations); written only by KnowledgeBase\Indexer with SQL, never through the EntityManager.
 *
 * Gone with its source by the foreign key (page version or item), whoever deletes it; its chunks go with it by theirs.
 * Whether it is still right is not stored: KnowledgeBase\Model\Freshness derives it from `basis` and the plan of the live source.
 * The CHECK constraint (exactly one of page_translation_id / item_id, matching kind) is in the migration only: DBAL does not compare checks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'itk_kb_document')]
#[ORM\UniqueConstraint(name: 'itk_kb_document_source', columns: ['kind', 'source_id', 'locale'])]
class KnowledgeDocument
{
    /** 'page:<id>:<locale>' | 'item:<id>:<locale>' (KnowledgeBase\Model\SourceRef::key()) */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $id;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $kind;

    #[ORM\Column(name: 'source_id', type: Types::STRING, length: 36)]
    public string $sourceId;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale;

    /** the template key of a page, the collection key of an item */
    #[ORM\Column(name: 'resource_key', type: Types::STRING, length: 64)]
    public string $resourceKey;

    #[ORM\ManyToOne(targetEntity: PageTranslation::class)]
    #[ORM\JoinColumn(name: 'page_translation_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    public ?PageTranslation $pageTranslation = null;

    #[ORM\ManyToOne(targetEntity: CollectionItem::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    public ?CollectionItem $item = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $title = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    public ?string $url = null;

    /** KnowledgeBase\Embedding\Space::id() of every chunk of this document */
    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $space = '';

    /** KnowledgeBase\Model\Basis */
    #[ORM\Column(type: Types::STRING, length: 40)]
    public string $basis = '';

    #[ORM\Column(name: 'chunk_count', type: Types::INTEGER)]
    public int $chunkCount = 0;

    #[ORM\Column(name: 'indexed_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $indexedAt;
}

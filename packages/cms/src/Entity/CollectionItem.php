<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\CollectionItemRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A record of a collection (no page of its own).
 *
 * translations: { ru: { draft: {…fields}, published: {…fields}|null, status: 'draft'|'published', updatedAt, publishedAt?, awaiting?, returned? } }
 */
#[ORM\Entity(repositoryClass: CollectionItemRepository::class)]
#[ORM\Table(name: 'itk_collection_item')]
#[ORM\Index(name: 'itk_collection_item_collection_idx', columns: ['collection_key', 'position'])]
#[ORM\Index(name: 'itk_collection_item_parent_idx', columns: ['parent_id'])]
class CollectionItem
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(name: 'collection_key', type: Types::STRING, length: 64)]
    public string $collection;

    #[ORM\Column(type: Types::INTEGER)]
    public int $position = 0;

    /** The record this one is nested in (collections with tree()); shared by all languages. */
    #[ORM\Column(name: 'parent_id', type: Types::STRING, length: 36, nullable: true)]
    public ?string $parentId = null;

    /** @var array<string, array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    public array $translations = [];

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $collection, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->collection = $collection;
        $this->createdAt = new \DateTimeImmutable();
    }
}

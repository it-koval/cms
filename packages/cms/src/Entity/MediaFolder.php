<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\MediaFolderRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A folder of the media library. Folders form a tree; a file lies in one folder or in none.
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: MediaFolderRepository::class)]
#[ORM\Table(name: 'itk_media_folder')]
#[ORM\Index(name: 'itk_media_folder_parent_idx', columns: ['parent_id'])]
class MediaFolder
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?MediaFolder $parent = null;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $name;

    #[ORM\Column(type: Types::INTEGER)]
    public int $position = 0;

    public function __construct(string $name, ?self $parent = null, int $position = 0)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->parent = $parent;
        $this->position = $position;
    }

    public function parentId(): ?string
    {
        return $this->parent?->id;
    }
}

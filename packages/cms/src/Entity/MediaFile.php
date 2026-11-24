<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\MediaFileRepository;
use Symfony\Component\Uid\Uuid;

/** A file of the media library, stored in public/uploads/media/Y/m/. */
#[ORM\Entity(repositoryClass: MediaFileRepository::class)]
#[ORM\Table(name: 'itk_media_file')]
#[ORM\Index(name: 'itk_media_file_folder_idx', columns: ['folder_id'])]
class MediaFile
{
    /** The «folder» filter value for files that lie in no folder. */
    public const NO_FOLDER = 'none';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    /** Path under the public dir, with a leading slash: /uploads/media/2026/10/photo-ab12.jpg */
    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $path;

    #[ORM\Column(name: 'original_name', type: Types::STRING, length: 255)]
    public string $originalName;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $mime;

    #[ORM\Column(name: 'file_size', type: Types::INTEGER)]
    public int $size = 0;

    #[ORM\Column(type: Types::INTEGER)]
    public int $width = 0;

    #[ORM\Column(type: Types::INTEGER)]
    public int $height = 0;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $alt = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title = '';

    #[ORM\ManyToOne(targetEntity: MediaFolder::class)]
    #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?MediaFolder $folder = null;

    /**
     * Focal point of an image: { x, y }, 0..1 of the width and the height (as the picture looks). Null: the centre.
     *
     * @var array{x: float, y: float}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $focal = null;

    /**
     * Frames by ratio: { "16:9": { x, y, w, h }, … }, each 0..1 of the picture. Used by the ratio variants (media_url(img, 960, '16:9')).
     *
     * @var array<string, array{x: float, y: float, w: float, h: float}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $crops = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $path, string $originalName, string $mime)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->path = $path;
        $this->originalName = $originalName;
        $this->mime = $mime;
        $this->title = $originalName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function folderId(): ?string
    {
        return $this->folder?->id;
    }

    public function isImage(): bool
    {
        return \str_starts_with($this->mime, 'image/');
    }
}

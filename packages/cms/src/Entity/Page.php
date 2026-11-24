<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\PageRepository;
use Symfony\Component\Uid\Uuid;

/**
 * One node of the site tree. The tree is shared by all languages; the content lives in PageTranslation.
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: PageRepository::class)]
#[ORM\Table(name: 'itk_page')]
#[ORM\Index(name: 'itk_page_parent_idx', columns: ['parent_id'])]
class Page
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    public ?Page $parent = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $position = 0;

    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $template = 'default';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /** @var Collection<string, PageTranslation> */
    #[ORM\OneToMany(targetEntity: PageTranslation::class, mappedBy: 'page', cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]
    public Collection $translations;

    public function __construct(string $template = 'default', ?self $parent = null, ?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->template = $template;
        $this->parent = $parent;
        $this->createdAt = new \DateTimeImmutable();
        $this->translations = new ArrayCollection();
    }

    public function translation(string $locale): ?PageTranslation
    {
        return $this->translations->get($locale);
    }

    public function parentId(): ?string
    {
        return $this->parent?->id;
    }
}

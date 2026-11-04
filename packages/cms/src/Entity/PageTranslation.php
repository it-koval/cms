<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\PageTranslationRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The version of a page in one language.
 *
 * draft / published: { title, url, seo: { title, description }, sections: [{ id, type, hidden, data }], fields: {} }
 * published is the snapshot of draft taken at publication; status 'published' means the snapshot is live on the site.
 */
#[ORM\Entity(repositoryClass: PageTranslationRepository::class)]
#[ORM\Table(name: 'itk_page_translation')]
#[ORM\UniqueConstraint(name: 'itk_page_translation_page_locale', columns: ['page_id', 'locale'])]
#[ORM\UniqueConstraint(name: 'itk_page_translation_url', columns: ['locale', 'published_url'])]
class PageTranslation
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Page::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Page $page;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale = 'ru';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    public array $draft = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $published = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $status = self::DRAFT;

    #[ORM\Column(name: 'published_url', type: Types::STRING, length: 255, nullable: true)]
    public ?string $publishedUrl = null;

    /** @var array{by: string, at: string}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $awaiting = null;

    /** @var array{by: string, at: string}|null */
    #[ORM\Column(name: 'returned_note', type: Types::JSON, nullable: true)]
    public ?array $returned = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    public bool $migrated = false;

    public function __construct(Page $page, string $locale)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->page = $page;
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isLive(): bool
    {
        return self::PUBLISHED === $this->status && null !== $this->published;
    }
}

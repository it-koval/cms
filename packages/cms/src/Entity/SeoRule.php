<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\SeoRuleRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Meta tags for an address of the site, whether a CMS page lives there or not (Настройки → SEO → Правила адресов).
 * `path` is a path with an optional query string ("/catalog", "/catalog?page=2"); `matchType` ("match" in the API) says if it stands for that address only
 * ('exact') or for everything below it too ('prefix': "/blog" covers "/blog" and "/blog/a", not "/blogger").
 * Empty text fields do not override anything: the page's own SEO (or the site's default) stays. `locale` null = every language.
 *
 * @internal
 */
#[ORM\Entity(repositoryClass: SeoRuleRepository::class)]
#[ORM\Table(name: 'itk_seo_rule')]
#[ORM\Index(name: 'itk_seo_rule_active_idx', columns: ['active'])]
class SeoRule
{
    public const EXACT = 'exact';
    public const PREFIX = 'prefix';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(type: Types::STRING, length: 500)]
    public string $path;

    #[ORM\Column(name: 'match_type', type: Types::STRING, length: 8)]
    public string $matchType = self::EXACT;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    public ?string $locale = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title = '';

    #[ORM\Column(type: Types::STRING, length: 500)]
    public string $description = '';

    #[ORM\Column(type: Types::STRING, length: 60)]
    public string $robots = '';

    #[ORM\Column(type: Types::STRING, length: 512)]
    public string $canonical = '';

    #[ORM\Column(name: 'og_image', type: Types::STRING, length: 512)]
    public string $ogImage = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    public bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $path, string $match = self::EXACT)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->path = $path;
        $this->matchType = $match;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    /** The path without the query string. */
    public function pathOnly(): string
    {
        return \explode('?', $this->path, 2)[0];
    }

    /**
     * The query of the rule as parameters ([] when it has none).
     *
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        $q = \explode('?', $this->path, 2)[1] ?? '';
        \parse_str($q, $params);

        return $params;
    }
}

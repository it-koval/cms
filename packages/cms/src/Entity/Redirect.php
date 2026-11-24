<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\RedirectRepository;
use Symfony\Component\Uid\Uuid;

/** A redirect from an old address. Created by hand, by import, or automatically when a published address changes. */
#[ORM\Entity(repositoryClass: RedirectRepository::class)]
#[ORM\Table(name: 'itk_redirect')]
#[ORM\UniqueConstraint(name: 'itk_redirect_from', columns: ['from_path'])]
class Redirect
{
    public const FROM_MAX = 255;
    public const TO_MAX = 512;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(name: 'from_path', type: Types::STRING, length: self::FROM_MAX)]
    public string $fromPath;

    #[ORM\Column(name: 'to_path', type: Types::STRING, length: self::TO_MAX)]
    public string $toPath;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $code = 301;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $fromPath, string $toPath, int $code = 301)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->fromPath = $fromPath;
        $this->toPath = $toPath;
        $this->code = $code;
        $this->createdAt = new \DateTimeImmutable();
    }
}

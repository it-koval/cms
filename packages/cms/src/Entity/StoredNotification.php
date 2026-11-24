<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A notification kept for the bell of the admin (Itk\Cms\Notification\Notification as DatabaseChannel stores it). Who has read it: itk_notification_read.
 * `audience` decides who sees it (null = everyone signed in; a permission area / 'area:action'; 'role:<key>'). `dedupeKey` keeps one row per matter.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'itk_notification')]
#[ORM\Index(name: 'itk_notification_created_idx', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'itk_notification_key_idx', columns: ['dedupe_key'])]
class StoredNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    public string $type;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $title;

    #[ORM\Column(type: Types::TEXT)]
    public string $body = '';

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    public ?string $link = null;

    /** info | warning | error */
    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $level = 'info';

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    public ?string $audience = null;

    #[ORM\Column(name: 'dedupe_key', type: Types::STRING, length: 120, nullable: true)]
    public ?string $dedupeKey = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $payload = [];

    /**
     * The texts of a notification of the core as keys of the catalogues: the bell draws them in the language of the reader. `title` and `body` above are the same texts in one language:
     * what notifications of a site and rows from before this column have, and the fallback for a key that left the catalogues.
     *
     * @var array{title?: array{key: string, params: array<string, mixed>}, body?: array{key: string, params: array<string, mixed>}}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $i18n = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $type, string $title)
    {
        $this->type = $type;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
    }
}

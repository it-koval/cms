<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\RequestRepository;

/** A request (заявка) from a form of the public site. */
#[ORM\Entity(repositoryClass: RequestRepository::class)]
#[ORM\Table(name: 'itk_request')]
#[ORM\Index(name: 'itk_request_created_idx', columns: ['created_at'])]
class Request
{
    public const WORK_NEW = 'new';
    public const WORK_IN_WORK = 'in_work';
    public const WORK_CLOSED = 'closed';
    public const WORK_SPAM = 'spam';
    public const WORK_STATUSES = [self::WORK_NEW, self::WORK_IN_WORK, self::WORK_CLOSED, self::WORK_SPAM];

    public const DELIVERY_PENDING = 'pending';
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    /** Key of the form in the schema. */
    #[ORM\Column(name: 'form_key', type: Types::STRING, length: 64)]
    public string $form;

    /** @var array<string, mixed> everything the visitor sent (validated fields) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $data = [];

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    public ?string $phone = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    public ?string $city = null;

    /** Private note of the staff. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $work = self::WORK_NEW;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $delivery = self::DELIVERY_PENDING;

    #[ORM\Column(name: 'delivery_error', type: Types::TEXT, nullable: true)]
    public ?string $deliveryError = null;

    #[ORM\Column(name: 'source_url', type: Types::STRING, length: 512, nullable: true)]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $locale = 'ru';

    #[ORM\Column(name: 'ip_hash', type: Types::STRING, length: 64, nullable: true)]
    public ?string $ipHash = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $form)
    {
        $this->form = $form;
        $this->createdAt = new \DateTimeImmutable();
    }
}

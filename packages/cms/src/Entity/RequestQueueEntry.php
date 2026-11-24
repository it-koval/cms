<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Request\SendRequestNotification;

/** An unpublished request notification, committed atomically with its request and reservation. */
#[ORM\Entity]
#[ORM\Table(name: 'itk_request_queue')]
#[ORM\Index(name: 'itk_request_queue_available_idx', columns: ['available_at'])]
class RequestQueueEntry
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 32)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Request::class)]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    public Request $request;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $kind;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $payload;

    #[ORM\Column(name: 'available_at', type: Types::INTEGER)]
    public int $availableAt = 0;

    public function __construct(Request $request, SendRequestNotification|SendMail $message)
    {
        $this->id = (new \Symfony\Component\Uid\Ulid())->toBase32();
        $this->request = $request;
        $this->kind = $message instanceof SendRequestNotification ? 'messenger' : 'mail';
        $this->payload = \get_object_vars($message);
    }
}

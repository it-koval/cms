<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * «Прочитано»: a person has seen a notification. Both ends cascade: a removed notification or user takes the mark with it.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'itk_notification_read')]
class NotificationRead
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: StoredNotification::class)]
    #[ORM\JoinColumn(name: 'notification_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public StoredNotification $notification;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $readAt;

    public function __construct(StoredNotification $notification, User $user)
    {
        $this->notification = $notification;
        $this->user = $user;
        $this->readAt = new \DateTimeImmutable();
    }
}

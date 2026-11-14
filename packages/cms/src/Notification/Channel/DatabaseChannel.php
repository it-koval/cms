<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;

/** The bell of the admin: keeps every notification in itk_notification; NotificationInbox shows it to the people of its audience. One row per `key`. */
final class DatabaseChannel implements NotificationChannelInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function supports(Notification $notification): bool
    {
        return true;
    }

    public function send(Notification $notification): void
    {
        if (null !== $notification->key && $this->em->getRepository(StoredNotification::class)->count(['dedupeKey' => $notification->key]) > 0) {
            return;
        }
        $row = new StoredNotification($notification->type, \mb_substr($notification->title, 0, 255));
        $row->body = \mb_substr($notification->body, 0, 2000);
        $row->link = null === $notification->link ? null : \mb_substr($notification->link, 0, 512);
        $row->level = $notification->level;
        $row->audience = '' === $notification->audience ? null : $notification->audience;
        $row->dedupeKey = $notification->key;
        $row->payload = $notification->payload;
        $row->i18n = $notification->i18n;
        $this->em->persist($row);
        $this->em->flush();
    }
}

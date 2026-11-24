<?php

declare(strict_types=1);

namespace Itk\Cms\Notification\Channel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
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

    public function send(Notification $notification, ?Connection $connection = null): void
    {
        $row = new StoredNotification($notification->type, \mb_substr($notification->title, 0, 255));
        $row->body = \mb_substr($notification->body, 0, 2000);
        $row->link = null === $notification->link ? null : \mb_substr($notification->link, 0, 512);
        $row->level = $notification->level;
        $row->audience = '' === $notification->audience ? null : $notification->audience;
        $row->dedupeKey = $notification->key;
        $row->payload = $notification->payload;
        $row->i18n = $notification->i18n;
        ($connection ?? $this->em->getConnection())->executeStatement(
            'INSERT INTO itk_notification (type, title, body, link, level, audience, dedupe_key, payload, i18n, created_at)
             VALUES (:type, :title, :body, :link, :level, :audience, :key, :payload, :i18n, :created)
             ON CONFLICT (dedupe_key) DO NOTHING',
            [
                'type' => $row->type,
                'title' => $row->title,
                'body' => $row->body,
                'link' => $row->link,
                'level' => $row->level,
                'audience' => $row->audience,
                'key' => $row->dedupeKey,
                'payload' => $row->payload,
                'i18n' => $row->i18n,
                'created' => $row->createdAt,
            ],
            ['payload' => Types::JSON, 'i18n' => Types::JSON, 'created' => Types::DATETIME_IMMUTABLE],
        );
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationChannelInterface;

/** Тестовый канал уведомлений: запоминает, что получил; по просьбе падает. Не сервис контейнера (создаётся через new). */
final class TestChannel implements NotificationChannelInterface
{
    /** @var list<string> */
    public static array $log = [];
    public static bool $fail = false;

    public function name(): string
    {
        return 'test';
    }

    public function supports(Notification $notification): bool
    {
        return 'test.chan' === $notification->type;
    }

    public function send(Notification $notification): void
    {
        if (self::$fail) {
            throw new \RuntimeException('канал сломан');
        }
        self::$log[] = $notification->title;
    }
}

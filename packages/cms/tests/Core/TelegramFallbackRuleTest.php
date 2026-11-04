<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** «Остальные формы» без чата: ошибка, только когда Telegram включён, у сервера нет своих чатов (ITK_TELEGRAM_CHAT_IDS) и не у каждой формы есть свой чат. Та же проверка есть в админке. */
final class TelegramFallbackRuleTest extends TestCase
{
    private const FORMS = ['call', 'maintenance'];

    /** @return iterable<string, array{bool, array<string, string>, bool, bool}> */
    public static function cases(): iterable
    {
        yield 'чата нет нигде' => [true, [], false, true];
        yield 'пустая строка вместо чата' => [true, ['*' => '  '], false, true];
        yield 'чат указан в настройках' => [true, ['*' => '-1002145550192'], false, false];
        yield 'чат только у одной формы' => [true, ['call' => '-1002145550192'], false, true];
        yield 'есть чаты в конфиге сервера' => [true, [], true, false];
        yield 'Telegram выключен' => [false, [], false, false];
        yield 'у каждой формы свой чат' => [true, ['call' => '-1002145550192', 'maintenance' => '@example_chat'], false, false];
        yield 'у одной из двух форм чат пустой' => [true, ['call' => '-1002145550192', 'maintenance' => ' '], false, true];
    }

    /** @param array<string, string> $routes */
    #[Test]
    #[DataProvider('cases')]
    public function requiresTheFallbackChatOnlyWhenNothingElseDeliversIt(bool $on, array $routes, bool $serverChat, bool $error): void
    {
        $message = SettingsService::routesError($on, $routes, $serverChat, self::FORMS);

        self::assertSame($error, '' !== $message, $message);
    }
}

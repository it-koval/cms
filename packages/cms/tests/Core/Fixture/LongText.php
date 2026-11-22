<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

/** Order lists of an exact length, with the total as the last line. */
final class LongText
{
    public const TOTAL = 'Итого: 123 456 ₽';

    public static function order(int $length): string
    {
        $room = $length - \mb_strlen(self::TOTAL) - 1;
        $lines = '';
        for ($i = 1; \mb_strlen($lines) < $room; ++$i) {
            $lines .= $i . ". Шлем кроссовый с визором, размер L, 1 шт., 15 900 ₽\n";
        }

        return \mb_substr($lines, 0, $room) . "\n" . self::TOTAL;
    }

    public static function cart(int $positions): string
    {
        $lines = [];
        for ($i = 1; $i <= $positions; ++$i) {
            $lines[] = $i . '. Куртка мембранная "Enduro & Rally" (синяя), артикул TEST-' . (10000 + $i) . ', размер XL, защита локтей и плеч, съёмная подкладка, 1 шт. × 24 900 ₽ = 24 900 ₽';
        }

        return \implode("\r\n", $lines) . "\r\n" . self::TOTAL;
    }
}

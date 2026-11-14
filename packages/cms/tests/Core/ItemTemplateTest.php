<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ItemTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Шаблон заголовка строки списка: пустые значения уносят с собой соседний текст (то же, что admin/src/utils/itemTemplate.test.js). */
final class ItemTemplateTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function cases(): iterable
    {
        $t = '{bike} · {yearFrom}–{yearTo}';
        yield 'all' => [$t, ['bike' => 'Beta', 'yearFrom' => '2020', 'yearTo' => '2022'], 'Beta · 2020–2022'];
        yield 'no years' => [$t, ['bike' => 'Beta'], 'Beta'];
        yield 'no bike' => [$t, ['yearFrom' => '2020', 'yearTo' => '2022'], '2020–2022'];
        yield 'no end' => [$t, ['bike' => 'Beta', 'yearFrom' => '2020'], 'Beta · 2020'];
        yield 'no start' => [$t, ['bike' => 'Beta', 'yearTo' => '2022'], 'Beta · 2022'];
        yield 'nothing' => [$t, [], ''];
        yield 'spaces only' => [$t, ['bike' => '  ', 'yearFrom' => "\n"], ''];
        yield 'zero is a value' => [$t, ['yearFrom' => '0'], '0'];
        yield 'prefix and suffix' => ['№ {sku}, {price} ₽', ['sku' => 'A1', 'price' => '900'], '№ A1, 900 ₽'];
        yield 'prefix needs its value' => ['№ {sku}', [], ''];
        yield 'suffix needs its value' => ['{price} ₽', [], ''];
        yield 'fixed text' => ['Вариант', [], 'Вариант'];
        yield 'unknown key' => ['{nope}', ['a' => 'x'], ''];
    }

    /**
     * @param array<string, string> $values
     */
    #[Test]
    #[DataProvider('cases')]
    public function render(string $template, array $values, string $expected): void
    {
        self::assertSame($expected, ItemTemplate::render($template, static fn (string $k): string => $values[$k] ?? ''));
    }

    #[Test]
    public function theFirstTemplateThatGivesSomethingWins(): void
    {
        $of = static fn (array $values): \Closure => static fn (string $k): string => $values[$k] ?? '';
        $templates = ['{bike} · {yearFrom}', '{title}'];
        self::assertSame('Полная комплектация', ItemTemplate::first($templates, $of(['title' => 'Полная комплектация'])));
        self::assertSame('Beta', ItemTemplate::first($templates, $of(['bike' => 'Beta', 'title' => 'X'])));
        self::assertSame('', ItemTemplate::first($templates, $of([])));
    }
}

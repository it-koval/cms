<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\User;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\FormFields;
use Itk\Cms\Field\Note;
use Itk\Cms\Field\Text;
use Itk\Cms\Security\Viewer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Заголовок раздела (section()) скрытого для человека поля переходит на следующее видимое поле, иначе оно окажется под прошлым разделом. */
final class FormFieldsTest extends TestCase
{
    private static function nobody(): Viewer
    {
        return Viewer::of(new User('test-form-fields@example.com', 'h', 'editor'), ['perms' => [], 'templates' => null]);
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<array{key: string, section: ?string}>
     */
    private function shape(array $fields, ?Viewer $viewer): array
    {
        return \array_map(static fn (array $a): array => ['key' => $a['k'], 'section' => $a['section'] ?? null], FormFields::toArray($fields, $viewer));
    }

    #[Test]
    public function theHeadingOfAHiddenFieldMovesToTheNextVisibleOne(): void
    {
        $viewer = self::nobody();
        $fields = [
            Text::make('Название', 'title'),
            Text::make('Цена', 'price')->section('Деньги')->canSee('set:nobody'),
            Text::make('Скидка', 'discount'),
            Text::make('Склад', 'stock')->section('Склад'),
            Text::make('Код', 'code'),
        ];

        self::assertSame(
            [['key' => 'title', 'section' => null], ['key' => 'discount', 'section' => 'Деньги'], ['key' => 'stock', 'section' => 'Склад'], ['key' => 'code', 'section' => null]],
            $this->shape($fields, $viewer),
        );
        self::assertSame('Цена', $fields[1]->toArray()['label'], 'the field itself is not changed');
        self::assertSame('Деньги', $fields[1]->toArray()['section']);
        self::assertNull($fields[2]->toArray()['section'] ?? null);
    }

    #[Test]
    public function aHeadingOfTheNextFieldWinsAndNothingIsCarriedWithoutAHiddenHeading(): void
    {
        $viewer = self::nobody();
        $fields = [
            Text::make('А', 'a')->section('Первый')->canSee('set:nobody'),
            Text::make('Б', 'b')->canSee('set:nobody'),
            Text::make('В', 'c')->section('Свой'),
            Text::make('Г', 'd')->canSee('set:nobody'),
            Note::make('Заметка', 'n'),
        ];

        self::assertSame([['key' => 'c', 'section' => 'Свой'], ['key' => 'n', 'section' => null]], $this->shape($fields, $viewer));
        self::assertSame([['key' => 'a', 'section' => 'Первый'], ['key' => 'b', 'section' => null], ['key' => 'c', 'section' => 'Свой'], ['key' => 'd', 'section' => null], ['key' => 'n', 'section' => null]], $this->shape($fields, null), 'no viewer: everything as it is');
    }
}

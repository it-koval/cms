<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Image;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Toggle;
use Itk\Cms\Resource\SettingsResource;

/** Тестовая группа настроек: по одному полю каждого вида, чтобы проверить приведение типов. */
final class TestSettings extends SettingsResource
{
    public function key(): string
    {
        return 'test_settings';
    }

    public function title(): string
    {
        return 'Тестовые настройки';
    }

    public function fields(): iterable
    {
        yield Text::make('Заголовок', 'testTitle')->translatable();
        yield Toggle::make('Включено', 'testOn');
        yield Number::make('Мест', 'testSeats');
        yield Image::make('Логотип', 'testLogo');
        yield Repeater::make('Список', 'testList')->fields(Text::make('Пункт', 'item'));
    }
}

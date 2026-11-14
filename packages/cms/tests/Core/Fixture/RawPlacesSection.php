<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Textarea;
use Itk\Cms\Resource\SectionResource;

/** Секция со списком, в элементах которого есть поле с сырым HTML (как маркеры карты). */
final class RawPlacesSection extends SectionResource
{
    public function key(): string
    {
        return 'test_raw_places';
    }

    public function title(): string
    {
        return 'Test места';
    }

    public function fields(): iterable
    {
        yield Repeater::make('Места', 'places')->fields(
            Text::make('Город', 'city'),
            Textarea::make('Маркер', 'marker')->raw(),
        );
        yield Repeater::make('Регионы', 'regions')->fields(
            Text::make('Название', 'title'),
            Repeater::make('Точки', 'points')->fields(Textarea::make('Код', 'code')->raw()),
        );
    }
}

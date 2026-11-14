<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Photos;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\SectionResource;

/** Секция со списком, в элементах которого есть поля только для чтения (бейдж, фото, штамп во вложенном списке). */
final class LockedRowsSection extends SectionResource
{
    public function key(): string
    {
        return 'test_locked_rows';
    }

    public function title(): string
    {
        return 'Test строки';
    }

    public function fields(): iterable
    {
        yield Repeater::make('Строки', 'rows')->fields(
            Text::make('Название', 'name'),
            Text::make('Бейдж', 'badge')->readonly(),
            Photos::make('Снимки', 'shots')->readonly(),
            Repeater::make('Шаги', 'steps')->fields(
                Text::make('Метка', 'label'),
                Text::make('Штамп', 'stamp')->readonly(),
            ),
        );
    }
}

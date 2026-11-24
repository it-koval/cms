<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Text;
use Itk\Cms\Resource\TemplateResource;

/** A test template of a page without sections. */
final class FlatTemplate extends TemplateResource
{
    public function key(): string
    {
        return 'test_flat';
    }

    public function title(): string
    {
        return 'Тестовая страница без секций';
    }

    public function fields(): iterable
    {
        yield Text::make('Название', 'title');
        yield Text::make('Подпись', 'caption')->max(20);
    }

    /** @return array<array-key, mixed> */
    public function parents(): array
    {
        return ['default'];
    }

    public function sections(): string|false
    {
        return false;
    }
}

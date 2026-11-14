<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Text;
use Itk\Cms\Resource\SectionResource;

/** Секция с закрытым полем (canSee) и полем «только чтение». */
final class GuardSection extends SectionResource
{
    public function key(): string
    {
        return 'test_guard_sec';
    }

    public function title(): string
    {
        return 'Test охраняемая секция';
    }

    public function fields(): iterable
    {
        yield Text::make('Открытое', 'shown');
        yield Text::make('Ключ', 'secret')->canSee('set:users');
        yield Text::make('Служебное', 'locked')->readonly();
    }
}

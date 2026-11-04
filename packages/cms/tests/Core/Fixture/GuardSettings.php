<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Text;
use Itk\Cms\Resource\SettingsResource;

/** Группа настроек с закрытым полем (canSee) и полем «только чтение». */
final class GuardSettings extends SettingsResource
{
    public function key(): string
    {
        return 'test_guard';
    }

    public function title(): string
    {
        return 'Test охраняемые настройки';
    }

    public function fields(): iterable
    {
        yield Text::make('Открытое', 'testShown');
        yield Text::make('Ключ', 'testSecret')->canSee('set:users');
        yield Text::make('Служебное', 'testLocked')->readonly();
    }
}

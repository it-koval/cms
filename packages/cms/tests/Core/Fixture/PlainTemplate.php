<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Resource\TemplateResource;

/** Тестовый шаблон без полей, как «Обычная страница»: смена шаблона не меняет черновик, меняется только сам шаблон. */
final class PlainTemplate extends TemplateResource
{
    public function key(): string
    {
        return 'test_plain';
    }

    public function title(): string
    {
        return 'Тестовая страница без полей';
    }

    public function fields(): iterable
    {
        return [];
    }
}

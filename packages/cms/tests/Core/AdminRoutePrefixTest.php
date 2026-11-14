<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\Core\Fixture\AdminMountTestCase;

/** Сайт импортирует маршруты админки с prefix: /cp (опция по умолчанию): админка на /cp/admin, защита та же. */
final class AdminRoutePrefixTest extends AdminMountTestCase
{
    protected static function adminPath(): ?string
    {
        return null;
    }

    protected static function routePrefix(): string
    {
        return '/cp';
    }
}

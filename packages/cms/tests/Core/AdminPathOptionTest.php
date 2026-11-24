<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\Core\Fixture\AdminMountTestCase;

/** itk_cms.admin_path = /cp: админка на /cp, а не на /admin. */
final class AdminPathOptionTest extends AdminMountTestCase
{
    protected static function adminPath(): string
    {
        return '/cp';
    }

    protected static function routePrefix(): string
    {
        return '';
    }
}

<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

return static function (SchemaBuilder $s): void {
    foreach (['menus', 'redirects', 'seo', 'notifications', 'mail', 'bots', 'theme', 'languages', 'users', 'mcp', 'seo-files'] as $key) {
        $s->settings($key)->fields(Field::text('fixture_' . \str_replace('-', '_', $key), 'Site value'));
    }
};

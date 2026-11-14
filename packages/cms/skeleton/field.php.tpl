<?php

declare(strict_types=1);

namespace @@namespace@@;

use Itk\Cms\Field\AbstractField;

/**
 * Custom field type “@@title@@”: @@class@@::make('Label', 'key').
 * The admin panel renders it with the component from @@jsPath@@ (type '@@type@@'); register the script in ItkExtension:
 *   $registry->adminAssets(js: ['@@jsUrl@@']);
 */
final class @@class@@ extends AbstractField
{
    protected function wireType(): string
    {
        return '@@type@@';
    }
}

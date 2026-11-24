<?php

declare(strict_types=1);

namespace @@namespace@@;

use Itk\Cms\Field\Text;
use Itk\Cms\Resource\SettingsResource;

/** Read in code with Itk\Cms\Settings\Settings::string('@@example@@'), in Twig with itk_settings('@@example@@'). */
final class @@class@@ extends SettingsResource
{
    public function key(): string
    {
        return '@@key@@';
    }

    public function title(): string
    {
        return @@titleQ@@;
    }

    public function fields(): iterable
    {
        yield Text::make('Text', '@@example@@')->max(120);
    }
}

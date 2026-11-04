<?php

declare(strict_types=1);

namespace @@namespace@@;

use Itk\Cms\Resource\TemplateResource;

final class @@class@@ extends TemplateResource
{
    public function key(): string
    {
        return '@@key@@';
    }

    public function title(): string
    {
        return @@titleQ@@;
    }

    public function about(): string
    {
        return '';
    }

    public function fields(): iterable
    {
        // yield Text::make('Subtitle', 'subtitle')->max(80);
        return [];
    }

    // Optional: parents(), single(), leaf(), listing(), h1(), card(), shortcut(), configure(Template $t) and the hooks. See the bundle README.
}

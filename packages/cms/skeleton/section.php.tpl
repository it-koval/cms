<?php

declare(strict_types=1);

namespace @@namespace@@;

use Itk\Cms\Field\Description;
use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Image;
use Itk\Cms\Resource\SectionResource;

final class @@class@@ extends SectionResource
{
    public function key(): string
    {
        return '@@key@@';
    }

    public function title(): string
    {
        return @@titleQ@@;
    }

    public function group(): string
    {
        return @@groupQ@@;
    }

    public function about(): string
    {
        return '';
    }

    public function fields(): iterable
    {
        yield Headline::make('Headline', 'headline')->max(60)->required();
        yield Description::make('Text', 'text')->max(400);
        yield Image::make('Image', 'image');
    }
}

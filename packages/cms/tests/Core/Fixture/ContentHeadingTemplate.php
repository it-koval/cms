<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Text;
use Itk\Cms\Resource\TemplateResource;

final class ContentHeadingTemplate extends TemplateResource
{
    public function key(): string
    {
        return 'r2_content_heading';
    }

    public function title(): string
    {
        return 'Content heading';
    }

    public function h1(): ?string
    {
        return 'heading';
    }

    public function fields(): iterable
    {
        yield Text::make('Heading', 'heading')->max(3);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App\Itk\Template;

use Itk\Cms\Field\Headline;
use Itk\Cms\Resource\TemplateResource;

/** The blog page (a core template that a class changes): its H1 is one headline field. */
final class BlogTemplate extends TemplateResource
{
    public function key(): string
    {
        return 'blog';
    }

    public function title(): string
    {
        return 'Блог';
    }

    public function fields(): iterable
    {
        yield Headline::make('Заголовок страницы (H1)', 'headline')->hint('Если оставить пустым, будет название страницы.');
    }

    public function h1(): ?string
    {
        return 'headline';
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** DESCRIPTION: prose with paragraphs, bold, italic, links, lists (HTML string). */
final class Description extends AbstractField
{
    protected function wireType(): string
    {
        return 'textarea';
    }

    protected function configure(): void
    {
        $this->definition['rich'] = true;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** The long text of an article (+ subheadings, quote, divider, photos). */
final class Article extends AbstractField
{
    protected function wireType(): string
    {
        return 'textarea';
    }

    protected function configure(): void
    {
        $this->definition['rich'] = true;
        $this->definition['article'] = true;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** Plain multi-line text. */
final class Textarea extends AbstractField
{
    protected function wireType(): string
    {
        return 'textarea';
    }
}

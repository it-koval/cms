<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** One plain line. */
final class Text extends AbstractField
{
    protected function wireType(): string
    {
        return 'text';
    }
}

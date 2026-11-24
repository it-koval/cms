<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A list of photos with individual alt text: {src, alt}; existing image addresses remain valid and keep their shape until edited. */
final class Photos extends AbstractField
{
    protected function wireType(): string
    {
        return 'photos';
    }
}

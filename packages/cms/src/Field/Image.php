<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A picture from the media library. Value: { src, alt }. */
final class Image extends AbstractField
{
    protected function wireType(): string
    {
        return 'image';
    }
}

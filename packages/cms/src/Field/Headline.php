<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A heading: HTML with line breaks (+ gradient words when the design has them). */
final class Headline extends AbstractField
{
    protected function wireType(): string
    {
        return 'headline';
    }
}

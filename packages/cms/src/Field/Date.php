<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A date as `YYYY-MM-DD` text. */
final class Date extends AbstractField
{
    protected function wireType(): string
    {
        return 'text';
    }

    protected function configure(): void
    {
        $this->definition['inputType'] = 'date';
    }

    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::DateRange;
    }
}

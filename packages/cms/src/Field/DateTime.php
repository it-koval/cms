<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A date and time as `YYYY-MM-DDTHH:MM` text. */
final class DateTime extends AbstractField
{
    protected function wireType(): string
    {
        return 'text';
    }

    protected function configure(): void
    {
        $this->definition['inputType'] = 'datetime-local';
    }

    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::DateRange;
    }
}

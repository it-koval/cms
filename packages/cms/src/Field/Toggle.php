<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A yes / no switch. */
final class Toggle extends AbstractField
{
    protected function wireType(): string
    {
        return 'toggle';
    }

    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::Toggle;
    }
}

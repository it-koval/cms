<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A number (integer or decimal). */
final class Number extends AbstractField
{
    /** The collapsed row of a repeater shows the number with thousands separators («110 000»); years and codes are left without. */
    public function grouped(bool $grouped = true): static
    {
        if ($grouped) {
            $this->definition['grouped'] = true;
        } else {
            unset($this->definition['grouped']);
        }

        return $this;
    }

    protected function wireType(): string
    {
        return 'number';
    }

    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::NumberRange;
    }
}

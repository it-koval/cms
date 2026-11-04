<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** One value out of a list. */
final class Select extends AbstractField
{
    protected function wireType(): string
    {
        return 'select';
    }

    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::Select;
    }

    /**
     * @param array<int|string, mixed> $options list of [value, label] pairs, or value => label
     */
    public function options(array $options): static
    {
        $opts = [];
        foreach ($options as $value => $item) {
            $opts[] = \is_array($item) ? [(string) $item[0], (string) $item[1]] : [(string) $value, (string) $item];
        }
        $this->definition['opts'] = $opts;

        return $this;
    }
}

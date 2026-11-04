<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A link to a record of another collection. Value: the record id (a list of ids with multiple()). */
final class Relation extends AbstractField
{
    protected function wireType(): string
    {
        return 'relation';
    }

    protected function defaultFilterKind(): FilterKind
    {
        return FilterKind::Relation;
    }

    /** Key of the collection the records come from. */
    public function to(string $collection): static
    {
        $this->definition['to'] = $collection;

        return $this;
    }

    /** Several records. */
    public function multiple(bool $multiple = true): static
    {
        $this->definition['multi'] = $multiple;

        return $this;
    }

    public function isMultiple(): bool
    {
        return !empty($this->definition['multi']);
    }
}

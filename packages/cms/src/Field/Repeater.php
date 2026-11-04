<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A list of items; every item has its own _id and the given sub-fields. */
final class Repeater extends AbstractField
{
    protected function wireType(): string
    {
        return 'repeater';
    }

    protected function configure(): void
    {
        $this->definition['sub'] = [];
    }

    /** @param FieldInterface|array<FieldInterface> ...$fields */
    public function fields(FieldInterface|array ...$fields): static
    {
        $list = [];
        foreach ($fields as $item) {
            foreach (\is_array($item) ? $item : [$item] as $field) {
                $list[] = $field;
            }
        }
        $this->definition['sub'] = $list;

        return $this;
    }

    /**
     * The title of a collapsed row. {key} is the value of the sub-field with that key, shown as a person reads it (a Relation by the title
     * of the record, a Select by the label of the option). An empty value takes the text next to it away, so the title never reads « · –»:
     * text between two values is printed only between two values that are there, text before the first and after the last one only next to a value.
     * Several templates are tried in order, the first that gives something wins: itemTitle('{bike} · {yearFrom}–{yearTo}', '{title}').
     * Nothing to show: the title the admin always had («Вариант 1» with noun('вариант')).
     */
    public function itemTitle(string $template, string ...$fallbacks): static
    {
        $this->definition['itemTitle'] = [$template, ...$fallbacks];

        return $this;
    }

    /** The line under the title of a collapsed row; the same templates as itemTitle(): itemSummary('{sku} · {price}'). Without it the admin shows a part of the first other text field. */
    public function itemSummary(string $template, string ...$fallbacks): static
    {
        $this->definition['itemSummary'] = [$template, ...$fallbacks];

        return $this;
    }

    /** A «Дублировать» button in every row: the copy goes right under the original, opens, and gets new _id (the items nested in it too). */
    public function duplicable(bool $duplicable = true): static
    {
        if ($duplicable) {
            $this->definition['dup'] = true;
        } else {
            unset($this->definition['dup']);
        }

        return $this;
    }
}

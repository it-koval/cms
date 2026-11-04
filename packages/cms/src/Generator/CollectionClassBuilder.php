<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

/**
 * Writes the PHP class of a collection from its spec.
 *
 * @internal
 */
final class CollectionClassBuilder
{
    public function __construct(private readonly SkeletonRenderer $skeleton)
    {
    }

    public function build(CollectionSpec $spec, string $namespace, string $class): string
    {
        $uses = ['Itk\\Cms\\Resource\\CollectionResource'];
        $attributes = '';
        if (null !== $spec->group && '' !== $spec->group) {
            $uses[] = 'Itk\\Cms\\Attribute\\Group';
            $attributes = '#[Group(' . Naming::php($spec->group) . ")]\n";
        }
        $lines = [];
        foreach ($spec->fields as $f) {
            $uses[] = 'Itk\\Cms\\Field\\' . $f->className();
            $lines[] = '        yield ' . $this->field($f) . ';';
        }

        $methods = '';
        if (null !== $spec->singular) {
            $methods .= "\n    public function singular(): ?string\n    {\n        return " . Naming::php($spec->singular) . ";\n    }\n";
        }
        if (null !== $spec->icon) {
            $methods .= "\n    public function icon(): ?string\n    {\n        return " . Naming::php($spec->icon) . ";\n    }\n";
        }
        if (null !== ($title = $spec->titleFieldKey())) {
            $methods .= "\n    public function titleField(): ?string\n    {\n        return " . Naming::php($title) . ";\n    }\n";
        }

        \sort($uses);

        return $this->skeleton->render('collection.php', [
            'namespace' => $namespace,
            'class' => $class,
            'key' => $spec->key,
            'titleQ' => Naming::php($spec->title),
            'uses' => \implode("\n", \array_map(static fn (string $u): string => 'use ' . $u . ';', \array_unique($uses))),
            'attributes' => $attributes,
            'methods' => $methods,
            'fields' => \implode("\n", $lines),
        ]);
    }

    private function field(FieldSpec $f): string
    {
        $code = $f->className() . '::make(' . Naming::php($f->label) . ', ' . Naming::php($f->key) . ')';
        if ('select' === $f->type) {
            $code .= '->options([' . \implode(', ', \array_map(static fn (string $o): string => Naming::php($o) . ' => ' . Naming::php($o), $f->options)) . '])';
        }
        if ($f->required) {
            $code .= '->required()';
        }
        if (null !== $f->max && \in_array($f->type, ['text', 'textarea', 'description', 'headline'], true)) {
            $code .= '->max(' . $f->max . ')';
        }
        $code .= match ($f->type) {
            'text' => '->searchable()->sortable()',
            'number' => '->sortable()->filterable()',
            'date', 'datetime' => '->sortable()->filterable()',
            'toggle', 'select' => '->filterable()',
            default => '',
        };

        return $code;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Attribute\CanSee;
use Itk\Cms\Attribute\Group;
use Itk\Cms\Attribute\HideFromMenu;
use Itk\Cms\Attribute\Icon;
use Itk\Cms\Attribute\Order;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Schema\Key;
use Itk\Cms\Schema\SchemaBuilder;

/** Turns a resource class into the same definitions $s->collection() / ->template() / ->section() / ->settings() make. */
final class ResourceApplier
{
    public static function apply(SchemaBuilder $b, ResourceInterface $r): void
    {
        $source = $r::class;
        match (true) {
            $r instanceof CollectionResource => self::collection($b, $r, $source),
            $r instanceof TemplateResource => self::template($b, $r, $source),
            $r instanceof SectionResource => self::section($b, $r, $source),
            $r instanceof SettingsResource => self::settings($b, $r, $source),
            default => throw new \LogicException(\sprintf('%s must extend CollectionResource, TemplateResource, SectionResource or SettingsResource.', $source)),
        };
    }

    private static function collection(SchemaBuilder $b, CollectionResource $r, string $source): void
    {
        $key = self::key($r, $source);
        $b->claim('collection', $key, $source);
        $c = $b->collection($key, $r->title())->bind($r)->fields(...self::fields($r, $source));
        if (null !== $r->singular()) {
            $c->singular($r->singular());
        }
        if (null !== $r->newLabel()) {
            $c->newLabel($r->newLabel());
        }
        if (null !== $r->icon()) {
            $c->icon($r->icon());
        }
        if ('' !== $r->description()) {
            $c->describe($r->description());
        }
        if (null !== ($empty = $r->emptyState())) {
            $c->emptyState((string) $empty['title'], (string) $empty['text']);
        }
        if ([] !== $r->views()) {
            $c->views($r->views());
        }
        if (null !== $r->titleField()) {
            $c->titleField($r->titleField());
        }
        $c->thumbField($r->thumbField())->subField($r->subField())->toggleField($r->toggleField());
        $c->sortable($r->sortable())->tree($r->tree())->perPage($r->perPage());
        if ([] !== $r->defaultSort()) {
            $c->defaultSort($r->defaultSort());
        }
        $c->filters(...self::list($r->filters(), FieldInterface::class, $source, 'filters()'));
        $c->actions(...self::list($r->actions(), Action::class, $source, 'actions()'));
        $c->metrics(...self::list($r->metrics(), Metric::class, $source, 'metrics()'));

        $class = new \ReflectionClass($r);
        $group = ($class->getAttributes(Group::class)[0] ?? null)?->newInstance();
        $order = ($class->getAttributes(Order::class)[0] ?? null)?->newInstance();
        $icon = ($class->getAttributes(Icon::class)[0] ?? null)?->newInstance();
        $see = ($class->getAttributes(CanSee::class)[0] ?? null)?->newInstance();
        $c->menu(
            group: $group?->name,
            order: $order?->order,
            icon: $icon?->name,
            hidden: [] !== $class->getAttributes(HideFromMenu::class),
            canSee: $see?->who,
            groupIcon: $group?->icon,
            groupOrder: $group?->order,
        );
    }

    private static function template(SchemaBuilder $b, TemplateResource $r, string $source): void
    {
        $key = self::key($r, $source);
        $b->claim('template', $key, $source);
        $t = $b->template($key, $r->title())->bind($r);
        if ('' !== $r->about()) {
            $t->about($r->about());
        }
        if ($r->root()) {
            $t->root();
        } elseif ($r->topLevel()) {
            $t->topLevel();
        } elseif ([] !== $r->parents()) {
            $t->parents(...$r->parents());
        }
        if ($r->single()) {
            $t->single();
        }
        if ($r->leaf()) {
            $t->leaf();
        }
        $t->sections($r->sections());
        if (null !== ($l = $r->listing())) {
            $t->listing(of: (array) ($l['of'] ?? []), label: (string) ($l['label'] ?? ''), onlyIf: $l['onlyIf'] ?? null, collection: $l['collection'] ?? null);
        }
        if (null !== $r->h1()) {
            $t->h1($r->h1());
        }
        if (null !== $r->shortcut()) {
            $t->shortcut($r->shortcut());
        }
        if (null !== ($c = $r->card())) {
            $t->card(image: $c['image'] ?? null, title: $c['title'] ?? null, text: $c['text'] ?? null, date: $c['date'] ?? null);
        }
        if (null !== ($a = $r->areas())) {
            $t->areas(top: $a['top'] ?? [], bottom: $a['bottom'] ?? []);
        }
        $fields = self::fields($r, $source);
        if ([] !== $fields) {
            $t->fields(...$fields);
        }
        $r->configure($t);
    }

    private static function section(SchemaBuilder $b, SectionResource $r, string $source): void
    {
        $key = self::key($r, $source);
        $b->claim('section', $key, $source);
        $s = $b->section($key, $r->title())->about($r->about())->template($r->template())->fields(...self::fields($r, $source));
        if (null !== $r->group()) {
            $s->group($r->group());
        }
        if (null !== $r->short()) {
            $s->short($r->short());
        }
        if (null !== $r->tone()) {
            $s->tone($r->tone());
        }
        if (null !== $r->silhouette()) {
            $s->silhouette($r->silhouette());
        }
        if (null !== $r->h1()) {
            $s->h1($r->h1());
        }
    }

    private static function settings(SchemaBuilder $b, SettingsResource $r, string $source): void
    {
        $key = self::key($r, $source);
        $b->claim('settings', $key, $source);
        $b->settings($key, $r->title())->describe($r->description())->fields(...self::fields($r, $source));
    }

    private static function key(ResourceInterface $r, string $source): string
    {
        $key = $r->key();
        if (!Key::isValid($key)) {
            throw new \LogicException(\sprintf('%s::key() must be Latin letters, digits, _ and -, got "%s".', $source, $key));
        }

        return $key;
    }

    /** @return list<FieldInterface> */
    private static function fields(ResourceInterface $r, string $source): array
    {
        return self::list($r->fields(), FieldInterface::class, $source, 'fields()');
    }

    /**
     * @template T of object
     *
     * @param iterable<mixed> $items
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    private static function list(iterable $items, string $type, string $source, string $method): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof $type) {
                throw new \LogicException(\sprintf('%s::%s must return %s objects, got %s.', $source, $method, $type, \get_debug_type($item)));
            }
            $out[] = $item;
        }

        return $out;
    }
}

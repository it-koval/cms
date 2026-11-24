<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Attribute\Order;
use Itk\Cms\Extension\Registry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every resource class of the site: the ones found by themselves (tag `itk.resource`), the ones extensions registered, and the ones
 * added from code (tests). The schema is built from this list.
 *
 * @internal
 */
final class ResourceRegistry
{
    /** @var array<class-string, ResourceInterface> */
    private array $added = [];

    /** @param iterable<ResourceInterface> $tagged */
    public function __construct(
        #[AutowireIterator('itk.resource')]
        private readonly iterable $tagged,
        private readonly Registry $extensions,
    ) {
    }

    /** Adds a resource before the schema is built. */
    public function add(ResourceInterface $resource): void
    {
        $this->added[$resource::class] = $resource;
    }

    /**
     * One object per class, in a stable order: sections, templates, settings, collections; collections by #[Order], then by key.
     *
     * @return list<ResourceInterface>
     */
    public function all(): array
    {
        $byClass = [];
        foreach ($this->tagged as $r) {
            $byClass[$r::class] = $r;
        }
        foreach ($this->extensions->resourceClasses() as $class) {
            $byClass[$class] ??= new $class();
        }
        $byClass = $this->added + $byClass;

        $rank = static fn (ResourceInterface $r): int => match (true) {
            $r instanceof SectionResource => 0,
            $r instanceof TemplateResource => 1,
            $r instanceof SettingsResource => 2,
            default => 3,
        };
        $order = static fn (ResourceInterface $r): int => ((new \ReflectionClass($r))->getAttributes(Order::class)[0] ?? null)?->newInstance()->order ?? 100;
        $list = \array_values($byClass);
        \usort($list, static fn (ResourceInterface $a, ResourceInterface $b): int => [$rank($a), $order($a), $a->key(), $a::class] <=> [$rank($b), $order($b), $b->key(), $b::class]);

        return $list;
    }
}

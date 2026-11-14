<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The schema.org graph (JSON-LD) of a live page before it is printed in the head. A listener adds the nodes of its own (a `Product` on a product page),
 * changes the ones the CMS made (`nodes()` returns them) or removes one by its `@var`. A node is an array with `@var`; the CMS adds `@context` and wraps the nodes in `@graph`.
 * The graph is built once per render of a live page (so it is kept in the cache of pages), never for the preview.
 */
final class SchemaOrgGraph extends Event
{
    /**
     * @param array{id: string, template: string, locale: string, title: string, url: string, fields: array<string, mixed>} $page the live snapshot of the page
     * @param string $base the address of the site without a trailing slash (`https://example.com`)
     * @param list<array<string, mixed>> $nodes
     */
    public function __construct(
        public readonly array $page,
        public readonly string $base,
        private array $nodes = [],
    ) {
    }

    /** @param array<string, mixed> $node */
    public function add(array $node): void
    {
        $this->nodes[] = $node;
    }

    /** Removes every node of this `@var`. */
    public function remove(string $type): void
    {
        $this->nodes = \array_values(\array_filter($this->nodes, static fn (array $n): bool => ($n['@type'] ?? null) !== $type));
    }

    /** @return list<array<string, mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }
}

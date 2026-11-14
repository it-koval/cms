<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

/**
 * A number above the list of a collection.
 *
 *   Metric::count('Всего')            Metric::count('Опубликовано', filter: ['status' => 'published'])            Metric::sum('Мест', 'seats')
 *   Metric::count('Впереди', filter: ['start' => ['from' => \date('Y-m-d')]])      a range works like the range filters of the list (from, to)
 */
final class Metric
{
    /**
     * @param 'count'|'sum' $kind
     * @param array<string, mixed> $filter field => value (the same values as a filter of the list)
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $field,
        public readonly array $filter,
    ) {
    }

    /** @param array<string, mixed> $filter */
    public static function count(string $label, array $filter = []): self
    {
        return new self('count', $label, null, $filter);
    }

    /** @param array<string, mixed> $filter */
    public static function sum(string $label, string $field, array $filter = []): self
    {
        return new self('sum', $label, $field, $filter);
    }
}

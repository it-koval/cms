<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

/**
 * The path of a value inside a jsonb column as the operators #> and #>> take it: a text array literal, {"ru","draft","title"}.
 *
 * @internal
 */
final class JsonPath
{
    public static function of(string ...$keys): string
    {
        return '{' . \implode(',', \array_map(static fn (string $k): string => '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $k) . '"', $keys)) . '}';
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

/**
 * The part of JSON Schema the tools use (type, enum, required, properties, additionalProperties, items, minimum / maximum, minLength / maxLength,
 * minItems / maxItems), enough to tell the model plainly what is wrong with its arguments before the tool runs.
 *
 * @internal
 */
final class ArgumentChecker
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> problems in English, each with the path of the value; empty = fine
     */
    public static function check(array $schema, mixed $value, string $path = 'arguments'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;
        if (null !== $type && !self::typeOk((array) $type, $value)) {
            return [\sprintf('%s must be %s, got %s', $path, \implode(' or ', (array) $type), self::typeName($value))];
        }
        if (isset($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $errors[] = \sprintf('%s must be one of: %s', $path, \implode(', ', \array_map(static fn (mixed $v): string => \is_string($v) ? $v : (string) \json_encode($v), $schema['enum'])));
        }
        if (\is_string($value)) {
            if (isset($schema['minLength']) && \mb_strlen($value) < $schema['minLength']) {
                $errors[] = \sprintf('%s must be at least %d characters', $path, $schema['minLength']);
            }
            if (isset($schema['maxLength']) && \mb_strlen($value) > $schema['maxLength']) {
                $errors[] = \sprintf('%s must be at most %d characters (got %d)', $path, $schema['maxLength'], \mb_strlen($value));
            }
        }
        if (\is_int($value) || \is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = \sprintf('%s must be at least %s', $path, $schema['minimum']);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = \sprintf('%s must be at most %s', $path, $schema['maximum']);
            }
        }
        if (\is_array($value) && \array_is_list($value) && ([] !== $value || 'array' === $type)) {
            if (isset($schema['minItems']) && \count($value) < $schema['minItems']) {
                $errors[] = \sprintf('%s must have at least %d items', $path, $schema['minItems']);
            }
            if (isset($schema['maxItems']) && \count($value) > $schema['maxItems']) {
                $errors[] = \sprintf('%s must have at most %d items (got %d)', $path, $schema['maxItems'], \count($value));
            }
            if (isset($schema['items']) && \is_array($schema['items'])) {
                foreach ($value as $i => $item) {
                    \array_push($errors, ...self::check($schema['items'], $item, $path . '[' . $i . ']'));
                }
            }
        }
        if (\is_array($value) && (!\array_is_list($value) || [] === $value) && (\in_array('object', (array) $type, true) || isset($schema['properties']))) {
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (!\array_key_exists($key, $value)) {
                    $errors[] = \sprintf('%s.%s is required', $path, $key);
                }
            }
            $props = (array) ($schema['properties'] ?? []);
            foreach ($value as $key => $item) {
                if (isset($props[$key]) && \is_array($props[$key])) {
                    \array_push($errors, ...self::check($props[$key], $item, $path . '.' . $key));
                } elseif (false === ($schema['additionalProperties'] ?? true)) {
                    $errors[] = \sprintf('%s.%s is not a known argument (known: %s)', $path, $key, \implode(', ', \array_keys($props)));
                }
            }
        }

        return $errors;
    }

    /** @param list<string> $types */
    private static function typeOk(array $types, mixed $v): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => \is_array($v) && (!\array_is_list($v) || [] === $v),
                'array' => \is_array($v) && \array_is_list($v),
                'string' => \is_string($v),
                'integer' => \is_int($v) || (\is_float($v) && \floor($v) === $v),
                'number' => \is_int($v) || \is_float($v),
                'boolean' => \is_bool($v),
                'null' => null === $v,
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function typeName(mixed $v): string
    {
        return match (true) {
            null === $v => 'null',
            \is_bool($v) => 'boolean',
            \is_int($v) => 'integer',
            \is_float($v) => 'number',
            \is_string($v) => 'string',
            \is_array($v) => \array_is_list($v) && [] !== $v ? 'array' : 'object',
            default => \get_debug_type($v),
        };
    }
}

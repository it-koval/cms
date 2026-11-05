<?php

declare(strict_types=1);

namespace Itk\Cms\Support;

/**
 * Helpers for JSON-ish data (PHP arrays decoded from JSON columns).
 *
 * jsonb reorders object keys inside JSON columns and decodes `{}` to `[]`, so every comparison goes through canonical().
 */
final class Json
{
    /** Order-insensitive normal form: assoc arrays get sorted keys, lists keep their order, null and missing keys are dropped from objects. */
    public static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\array_is_list($value)) {
            return \array_map(self::canonical(...), $value);
        }
        $out = [];
        \ksort($value);
        foreach ($value as $k => $v) {
            if (null === $v) {
                continue;
            }
            $out[$k] = self::canonical($v);
        }

        return $out;
    }

    /** Equality of two JSON values; `[]` and `{}` are the same, key order does not matter. */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::encode(self::canonical($a)) === self::encode(self::canonical($b));
    }

    public static function encode(mixed $value): string
    {
        return (string) \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /** An empty array must reach the browser as `{}` where the admin expects an object. */
    public static function obj(mixed $value): mixed
    {
        return \is_array($value) && [] === $value ? new \stdClass() : $value;
    }

    /** Removes every `_id` key (ids of repeater items), recursively. */
    public static function stripIds(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ('_id' === $k) {
                continue;
            }
            $out[$k] = self::stripIds($v);
        }

        return $out;
    }

    public static function get(mixed $data, string $path): mixed
    {
        foreach (\explode('.', $path) as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function set(array &$data, string $path, mixed $value): void
    {
        $keys = \explode('.', $path);
        $ref = &$data;
        foreach ($keys as $i => $key) {
            if ($i === \count($keys) - 1) {
                $ref[$key] = $value;

                return;
            }
            if (!isset($ref[$key]) || !\is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
    }

    /** UTC ISO time like JS toISOString(). */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Short random id with a prefix, like the admin's nid(). */
    public static function nid(string $prefix = 'n'): string
    {
        return $prefix . \substr(\bin2hex(\random_bytes(5)), 0, 7);
    }
}

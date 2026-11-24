<?php

declare(strict_types=1);

namespace Itk\Cms\Support;

/** Latin slugs from Russian and Kazakh text. */
final class Slug
{
    public static function slugify(string $text, int $max = 64): string
    {
        /** @var array<string, string>|null $map */
        static $map = null;
        $map ??= \json_decode(\file_get_contents(__DIR__ . '/slug-map.json') ?: throw new \RuntimeException('Cannot read slug-map.json'), true, 512, \JSON_THROW_ON_ERROR);
        $s = \strtr(\mb_strtolower(\trim($text)), $map);
        $s = (string) \preg_replace('/[^a-z0-9]+/', '-', $s);

        return \trim(\substr(\trim($s, '-'), 0, $max), '-');
    }

    /** A key for a new menu area: latin, starts with a letter, unique among `taken`. @param list<string> $taken */
    public static function areaKey(string $name, array $taken = []): string
    {
        $base = \str_replace('-', '_', self::slugify($name, 32)) ?: 'menu';
        if (!\preg_match('/^[a-z]/', $base)) {
            $base = 'menu_' . $base;
        }
        $key = $base;
        for ($n = 2; \in_array($key, $taken, true); ++$n) {
            $key = $base . '_' . $n;
        }

        return $key;
    }
}

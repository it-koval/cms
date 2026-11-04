<?php

declare(strict_types=1);

namespace Itk\Cms\Support;

/** Latin slugs from Russian text. */
final class Slug
{
    /** The transliteration table of the Russian alphabet: a rule for the content of the site, not a text of the interface. */
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', // i18n-ignore
        'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', // i18n-ignore
        'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', // i18n-ignore
    ];

    public static function slugify(string $text, int $max = 64): string
    {
        $s = \strtr(\mb_strtolower(\trim($text)), self::MAP);
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

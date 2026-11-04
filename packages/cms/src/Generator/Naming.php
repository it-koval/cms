<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;

/**
 * Names for the generators: keys → class names, labels, quoted PHP strings.
 *
 * @internal
 */
final class Naming
{
    /**
     * Columns people name the same way everywhere: the normalised name (lower case, no «_» and «-») → the part of the key of the label in the catalogue (model.label_<part>).
     * Anything else is the humanised key (the developer edits it).
     */
    private const LABELS = [
        'name' => 'name', 'title' => 'title', 'text' => 'text', 'description' => 'description', 'city' => 'city', 'country' => 'country', 'address' => 'address',
        'phone' => 'phone', 'email' => 'email', 'url' => 'url', 'link' => 'url', 'image' => 'image', 'photo' => 'photo', 'price' => 'price', 'date' => 'date',
        'datestart' => 'datestart', 'dateend' => 'dateend', 'start' => 'start', 'end' => 'end', 'status' => 'status', 'comment' => 'comment',
        'position' => 'position', 'sort' => 'position', 'active' => 'active', 'published' => 'published', 'created' => 'created', 'createdat' => 'created', 'updatedat' => 'updatedat',
    ];

    public static function isKey(string $key): bool
    {
        return 1 === \preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key);
    }

    /** blog_article → BlogArticle. */
    public static function studly(string $key): string
    {
        return \implode('', \array_map(\ucfirst(...), \preg_split('/[^A-Za-z0-9]+/', self::words($key), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** blog_article → blogArticle. */
    public static function camel(string $key): string
    {
        return \lcfirst(self::studly($key));
    }

    /** tour_list → «Tour list» (the starting point of a title). */
    public static function humanize(string $key): string
    {
        $words = \mb_strtolower(\trim(self::words($key)));

        return '' === $words ? $key : \mb_strtoupper(\mb_substr($words, 0, 1)) . \mb_substr($words, 1);
    }

    /** A label for a column / key: from the short dictionary, else the humanised key. */
    public static function label(string $key): string
    {
        $label = self::LABELS[\strtolower(\str_replace(['_', '-'], '', $key))] ?? null;

        if (null === $label) {
            return self::humanize($key);
        }
        $text = 'model.label_' . $label; // i18n-dynamic: model.label_

        return I18n::t($text);
    }

    /** Key from a table name: modx_tour_list (strip «modx_») → tour_list. */
    public static function keyOf(string $name, string $stripPrefix = ''): string
    {
        if ('' !== $stripPrefix && \str_starts_with($name, $stripPrefix)) {
            $name = \substr($name, \strlen($stripPrefix));
        }
        $key = \strtolower((string) \preg_replace('/[^A-Za-z0-9]+/', '_', self::words($name)));
        $key = \trim($key, '_');

        return 1 === \preg_match('/^[0-9]/', $key) ? 't_' . $key : $key;
    }

    /** A single-quoted PHP string. */
    public static function php(string $value): string
    {
        return "'" . \str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /** A JavaScript string (double quotes, Unicode as is). */
    public static function js(string $value): string
    {
        return (string) \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG);
    }

    /** dateStart → date_start (camelCase split into words), separators → spaces. */
    private static function words(string $key): string
    {
        return \str_replace(['_', '-'], ' ', (string) \preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key));
    }
}

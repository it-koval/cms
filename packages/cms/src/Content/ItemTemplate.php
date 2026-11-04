<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

/**
 * The text of a title/summary template of a repeater row (Repeater::itemTitle / itemSummary). Mirrors admin/src/utils/itemTemplate.js:
 * {key} is a value, an empty value takes the text next to it away (text between two values is printed only between two values that are there,
 * text before the first and after the last one only next to a value), several templates are tried in order.
 *
 * @internal
 */
final class ItemTemplate
{
    /**
     * @param list<string> $templates
     * @param callable(string): string $valueOf the text of the value with that key ('' when empty)
     */
    public static function first(array $templates, callable $valueOf): string
    {
        foreach ($templates as $template) {
            $s = self::render($template, $valueOf);
            if ('' !== $s) {
                return $s;
            }
        }

        return '';
    }

    /** @param callable(string): string $valueOf */
    public static function render(string $template, callable $valueOf): string
    {
        $parts = \preg_split('~\{([A-Za-z0-9_]+)\}~', $template, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return '';
        }
        $lits = [];
        $keys = [];
        foreach ($parts as $i => $part) {
            if (0 === $i % 2) {
                $lits[] = $part;
            } else {
                $keys[] = $part;
            }
        }
        if ([] === $keys) {
            return self::tidy($template);
        }
        $out = '';
        $sep = null;
        foreach ($keys as $i => $key) {
            $sep ??= $lits[$i];
            $v = self::tidy($valueOf($key));
            if ('' === $v) {
                continue;
            }
            $out .= '' !== $out ? $sep . $v : (0 === $i ? $lits[0] : '') . $v;
            $sep = null;
            if ($i === \count($keys) - 1) {
                $out .= $lits[\count($keys)];
            }
        }

        return self::tidy($out);
    }

    private static function tidy(string $s): string
    {
        return \trim((string) \preg_replace('~\s+~u', ' ', $s));
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * The ordered union of several stylesheets that are each a subsequence of the same source CSS (the critical CSS of each viewport).
 *
 * A stylesheet is split into top-level items: plain rules, statements (`@import …;`) and block at-rules. A rule is known by its text,
 * a block at-rule by its prelude, and the nth repeat of a key is its own item, so two separate `@media` blocks with one prelude stay apart.
 * `@media`, `@supports`, `@layer` and `@container` blocks present in both lists have their children merged the same way; every other
 * block (`@font-face`, `@keyframes`, …) is a whole-text item.
 *
 * Where only one list has the next item, a statement (`@import`, `@charset`) goes before a rule, so the browser does not drop it. A list that
 * holds only the second of two blocks with one prelude has it merged into the first one: a rule may then appear twice, the later copy wins.
 *
 * @internal
 */
final class CssUnion
{
    private const MERGED_BLOCKS = ['media', 'supports', 'layer', 'container'];

    /**
     * @param list<string> $stylesheets
     *
     * @return string|null null when a stylesheet is not well-formed or the item order of two of them cannot be reconciled
     */
    public static function of(array $stylesheets): ?string
    {
        $union = null;
        foreach ($stylesheets as $css) {
            $items = self::split($css);
            if (null === $items) {
                return null;
            }
            $union = null === $union ? $items : self::merge($union, $items);
            if (null === $union) {
                return null;
            }
        }

        return null === $union ? null : self::text($union);
    }

    /**
     * @param list<array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string}> $a
     * @param list<array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string}> $b
     *
     * @return list<array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string}>|null
     */
    private static function merge(array $a, array $b): ?array
    {
        $posA = \array_flip(\array_column($a, 'key'));
        $posB = \array_flip(\array_column($b, 'key'));
        $out = [];
        $i = $j = 0;
        while ($i < \count($a) && $j < \count($b)) {
            $x = $a[$i];
            $y = $b[$j];
            if ($x['key'] === $y['key']) {
                $item = self::mergeItem($x, $y);
                if (null === $item) {
                    return null;
                }
                $out[] = $item;
                ++$i;
                ++$j;
            } elseif (self::isStatement($y) && !self::isStatement($x) && (!isset($posA[$y['key']]) || $posA[$y['key']] < $i)) {
                $out[] = $y;
                ++$j;
            } elseif (!isset($posB[$x['key']]) || $posB[$x['key']] < $j) {
                $out[] = $x;
                ++$i;
            } elseif (!isset($posA[$y['key']]) || $posA[$y['key']] < $i) {
                $out[] = $y;
                ++$j;
            } else {
                return null;
            }
        }
        for (; $i < \count($a); ++$i) {
            $out[] = $a[$i];
        }
        for (; $j < \count($b); ++$j) {
            $out[] = $b[$j];
        }

        return $out;
    }

    /**
     * @param array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string} $x
     * @param array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string} $y
     *
     * @return array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string}|null
     */
    private static function mergeItem(array $x, array $y): ?array
    {
        if (null === $x['children'] || null === $y['children'] || $x['text'] === $y['text']) {
            return $x;
        }
        $children = self::merge($x['children'], $y['children']);
        if (null === $children) {
            return null;
        }

        return ['key' => $x['key'], 'prelude' => $x['prelude'], 'children' => $children, 'text' => $x['prelude'] . '{' . self::text($children) . '}'];
    }

    /** @param array{children: list<array<string, mixed>>|null, text: string} $item */
    private static function isStatement(array $item): bool
    {
        return null === $item['children'] && \str_ends_with($item['text'], ';');
    }

    /** @param list<array<string, mixed>> $items */
    private static function text(array $items): string
    {
        return \implode('', \array_column($items, 'text'));
    }

    /** @return list<array{key: string, prelude: string|null, children: list<array<string, mixed>>|null, text: string}>|null */
    private static function split(string $css): ?array
    {
        $items = [];
        $seen = [];
        $len = \strlen($css);
        $pos = 0;
        while (true) {
            $pos = self::skipSpace($css, $pos);
            if ($pos >= $len) {
                return $items;
            }
            $start = $pos;
            $stop = self::scanTo($css, $pos, ';{');
            if (null === $stop) {
                return null;
            }
            $prelude = \trim(\substr($css, $start, $stop - $start));
            if ('' === $prelude || '}' === $prelude[0]) {
                return null;
            }
            if (';' === $css[$stop]) {
                if ('@' !== $prelude[0]) {
                    return null;
                }
                $text = $prelude . ';';
                $key = $text;
                $children = null;
                $pos = $stop + 1;
            } else {
                $end = self::closing($css, $stop + 1);
                if (null === $end) {
                    return null;
                }
                $body = \substr($css, $stop + 1, $end - $stop - 1);
                $text = $prelude . '{' . \trim($body) . '}';
                $pos = $end + 1;
                $children = null;
                $key = $text;
                if ('@' === $prelude[0] && 1 === \preg_match('~^@([a-z-]+)~i', $prelude, $m) && \in_array(\strtolower($m[1]), self::MERGED_BLOCKS, true)) {
                    $children = self::split($body);
                    if (null === $children) {
                        return null;
                    }
                    $key = (string) \preg_replace('~\s+~', ' ', $prelude);
                    $text = $prelude . '{' . self::text($children) . '}';
                }
            }
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $items[] = ['key' => $key . "\x1f" . $seen[$key], 'prelude' => $prelude, 'children' => $children, 'text' => $text];
        }
    }

    private static function skipSpace(string $css, int $pos): int
    {
        $len = \strlen($css);
        while ($pos < $len) {
            if (\ctype_space($css[$pos])) {
                ++$pos;
            } elseif ('/*' === \substr($css, $pos, 2)) {
                $end = \strpos($css, '*/', $pos + 2);
                if (false === $end) {
                    return $len;
                }
                $pos = $end + 2;
            } else {
                break;
            }
        }

        return $pos;
    }

    /** The position of the first of $stops outside strings and comments at or after $pos, or null. */
    private static function scanTo(string $css, int $pos, string $stops): ?int
    {
        $len = \strlen($css);
        while ($pos < $len) {
            $c = $css[$pos];
            if (\str_contains($stops, $c)) {
                return $pos;
            }
            $pos = self::skipOpaque($css, $pos);
        }

        return null;
    }

    /** The position of the `}` that closes a block whose body starts at $pos, or null when it never closes. */
    private static function closing(string $css, int $pos): ?int
    {
        $len = \strlen($css);
        $depth = 1;
        while ($pos < $len) {
            $c = $css[$pos];
            if ('{' === $c) {
                ++$depth;
            } elseif ('}' === $c && 0 === --$depth) {
                return $pos;
            }
            $pos = self::skipOpaque($css, $pos);
        }

        return null;
    }

    /** The position after the character at $pos, or after the whole string or comment that starts there. */
    private static function skipOpaque(string $css, int $pos): int
    {
        $len = \strlen($css);
        $c = $css[$pos];
        if ('\\' === $c) {
            return $pos + 2;
        }
        if ('"' === $c || "'" === $c) {
            for ($i = $pos + 1; $i < $len; ++$i) {
                if ('\\' === $css[$i]) {
                    ++$i;
                } elseif ($css[$i] === $c) {
                    return $i + 1;
                }
            }

            return $len;
        }
        if (('u' === $c || 'U' === $c) && 'url(' === \strtolower(\substr($css, $pos, 4)) && 1 !== \preg_match('~\G\s*["\']~', $css, $q, 0, $pos + 4)) {
            $end = \strpos($css, ')', $pos + 4);

            return false === $end ? $len : $end + 1;
        }
        if ('/' === $c && '*' === ($css[$pos + 1] ?? '')) {
            $end = \strpos($css, '*/', $pos + 2);

            return false === $end ? $len : $end + 2;
        }

        return $pos + 1;
    }
}

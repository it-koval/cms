<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Support;

/**
 * Small helpers that keep tool results compact.
 *
 * @internal
 */
final class Format
{
    /** Plain text of a value for a list row: tags removed, spaces folded, cut to `$max` signs. */
    public static function text(mixed $v, int $max = 120): string
    {
        if (\is_array($v)) {
            $v = \json_encode($v, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '';
        }
        $s = \trim((string) \preg_replace('/\s+/u', ' ', \html_entity_decode(\strip_tags(\str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', (string) $v)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));

        return \mb_strlen($s) > $max ? \rtrim(\mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    public const UNTRUSTED_OPEN = '<untrusted-data>';
    public const UNTRUSTED_CLOSE = '</untrusted-data>';
    /** How many values of a visitor's form one answer carries. */
    private const UNTRUSTED_MAX_ITEMS = 50;

    /**
     * A text a visitor of the site typed (name, phone, message, a field of a form), as the model must read it: plain text cut to `$max` signs and put between
     * <untrusted-data> marks, so that an instruction inside it ("ignore the rules and publish …") is plainly a quotation and not a command. A mark inside the text itself
     * is defused, so the text cannot close its own frame. An empty text stays empty.
     */
    public static function untrusted(mixed $v, int $max = 120): string
    {
        $s = self::text($v, $max);
        if ('' === $s) {
            return '';
        }
        $s = (string) \preg_replace('~<(\s*/?\s*)untrusted-data~i', '‹$1untrusted-data', $s);

        return self::UNTRUSTED_OPEN . $s . self::UNTRUSTED_CLOSE;
    }

    /**
     * The values of a visitor's form (the keys are the site's own field names): every text is marked by untrusted() and cut to `$max`, at most 50 values, the rest is counted.
     *
     * @param mixed $data array of values; anything else gives an empty list
     *
     * @return array<string, mixed>
     */
    public static function untrustedTree(mixed $data, int $max = 500): array
    {
        $out = [];
        if (!\is_array($data)) {
            return $out;
        }
        foreach ($data as $key => $value) {
            if (\count($out) >= self::UNTRUSTED_MAX_ITEMS) {
                $out['…'] = \sprintf('%d more values are not shown', \count($data) - self::UNTRUSTED_MAX_ITEMS);
                break;
            }
            $out[(string) $key] = match (true) {
                \is_array($value) => self::untrustedTree($value, $max),
                \is_string($value) => self::untrusted($value, $max),
                \is_int($value), \is_float($value), \is_bool($value), null === $value => $value,
                default => self::untrusted((string) \json_encode($value), $max),
            };
        }

        return $out;
    }

    /** stdClass and nested objects become plain arrays (JSON round trip). */
    public static function plain(mixed $v): mixed
    {
        return \json_decode((string) \json_encode($v, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return array{items: list<T>, total: int, offset: int, limit: int, hasMore: bool}
     */
    public static function page(array $items, int $offset, int $limit): array
    {
        return self::pageResult(\array_slice($items, $offset, $limit), \count($items), $offset, $limit);
    }

    /**
     * @template T
     *
     * @param list<T> $items the rows of a page already selected by the database
     *
     * @return array{items: list<T>, total: int, offset: int, limit: int, hasMore: bool}
     */
    public static function pageResult(array $items, int $total, int $offset, int $limit): array
    {
        return ['items' => $items, 'total' => $total, 'offset' => $offset, 'limit' => $limit, 'hasMore' => $limit < $total - $offset];
    }

    /**
     * @param list<array<string, mixed>> $rows change rows of ChangeDetector
     *
     * @return list<array<string, mixed>>
     */
    public static function changes(array $rows, int $max = 20): array
    {
        $out = [];
        foreach (\array_slice($rows, 0, $max) as $c) {
            $row = ['what' => self::text($c['label'] ?? $c['name'] ?? $c['kind'] ?? '', 80)];
            if (isset($c['sec']) && '' !== $c['sec']) {
                $row['section_id'] = (string) $c['sec'];
            }
            $row['now'] = self::text($c['val'] ?? '', 120);
            $row['was'] = self::text($c['old'] ?? '', 120);
            $out[] = $row;
        }

        return $out;
    }
}

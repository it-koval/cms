<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

/**
 * Cuts the HTML of a message: after a line break, a line longer than a message between two characters, never inside a tag or an entity.
 * An element that fits in a message stays whole. A longer one is closed at the cut and opened again in the next message.
 * A message that a messenger would refuse as empty is left out: it holds only white space and invisible characters.
 *
 * @internal
 */
final class MessageSplitter
{
    /** A tag: a `>` inside a quoted attribute does not end it, `</>` closes the element that is open. */
    public const TAG = '</?[a-zA-Z](?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>|</>';

    /** What a cut never divides. */
    private const PIECE = '~' . self::TAG . '|&(?:\#[0-9]{1,8}|\#[xX][0-9a-fA-F]{1,6}|[a-zA-Z][a-zA-Z0-9]{0,31});|\r\n|.~su';

    /**
     * What TDLib takes for nothing (misc.cpp: strip_empty_characters, clean_input_string). Not \p{C}: private use and code points
     * newer than the Unicode of PCRE are visible, and a part of only those must be sent.
     */
    private const NOT_EMPTY = '~[^\x00-\x20\x{A0}\x{30A}\x{333}\x{33F}\x{1680}\x{180E}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}\x{2800}\x{3000}\x{FEFF}\x{FFFC}\x{E0000}-\x{E007F}]~u';

    /**
     * @param int $limit the longest message, as the messenger counts it
     * @param int $maxBytes the most bytes of HTML in one message
     * @param \Closure(string): int $length the length of one piece as the messenger counts it
     */
    public function __construct(
        private readonly int $limit,
        private readonly int $maxBytes,
        private readonly \Closure $length,
    ) {
    }

    /** @return non-empty-list<string> */
    public function split(string $html): array
    {
        if (false === \preg_match_all(self::PIECE, $html, $found)) {
            return [$html];
        }
        $pieces = $found[0];
        if ($this->fits($this->size($pieces), \strlen($html))) {
            return [$html];
        }

        $texts = [];
        $open = []; // the tags that are open where the next message starts
        for ($from = 0, $count = \count($pieces); $from < $count; $from = $end) {
            $cut = $this->cut($pieces, $from, $open);
            if (null === $cut) { // markup too long to repeat (an address of thousands of characters): it goes as plain text, so nothing of it is lost
                return $this->split(\strtr($html, ['<' => '&lt;', '>' => '&gt;']));
            }
            [$end, $next, $visible] = $cut;
            if ($visible) {
                $texts[] = \implode('', $open) . \implode('', \array_slice($pieces, $from, $end - $from)) . \implode('', self::closing($next));
            }
            $open = $next;
        }

        return [] !== $texts ? $texts : [$html];
    }

    /**
     * Where the message that starts at $from ends.
     *
     * @param list<string> $pieces
     * @param list<string> $open
     *
     * @return array{int, list<string>, bool}|null the next piece, the tags open there, is there anything to see; null when the markup cannot be repeated
     */
    private function cut(array $pieces, int $from, array $open): ?array
    {
        $size = $this->size($open);
        $bytes = \strlen(\implode('', $open));
        $stack = $open;
        $openedAt = $from; // where the outermost open element starts
        $last = null; // the last place that fits
        $line = null; // the last line break that fits
        $outside = null; // the last line break that fits outside any element
        $visible = false;
        $endsLine = false;
        for ($i = $from, $count = \count($pieces); $i < $count; ++$i) {
            $piece = $pieces[$i];
            $size += ($this->length)($piece);
            $bytes += \strlen($piece);
            if (!$this->fits($size, $bytes) && null !== $last) {
                break;
            }
            if (self::isTag($piece)) {
                if ([] === $stack) {
                    $openedAt = $i;
                }
                $stack = self::nest($stack, $piece);
                $repeated = [...$stack, $piece];
                if (4 * $this->size($repeated) > 3 * $this->limit || 4 * \strlen(\implode('', $repeated)) > 3 * $this->maxBytes) {
                    return null;
                }
            } elseif (!$visible) {
                $visible = 1 === \preg_match(self::NOT_EMPTY, \html_entity_decode($piece, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            }
            $endsLine = \str_ends_with($piece, "\n") || ($endsLine && \str_starts_with($piece, '</'));
            if ($i + 1 < $count && (self::opens($piece) || \str_starts_with($pieces[$i + 1], '</'))) {
                continue; // a cut leaves no empty element on either side
            }
            $closing = self::closing($stack);
            $fits = $this->fits($size + $this->size($closing), $bytes + \strlen(\implode('', $closing)));
            if (!$fits && null !== $last) {
                continue;
            }
            $last = [$i + 1, $stack, $visible];
            if (!$fits) {
                break;
            }
            if ($endsLine && $visible) {
                $line = ['cut' => $last, 'element' => $openedAt];
                if ([] === $stack) {
                    $outside = $last;
                }
            }
        }
        if (null === $last || $count === $last[0] || null === $line) {
            return $last;
        }
        if (null !== $outside && $outside !== $line['cut'] && $this->elementFits($pieces, $line['element'])) {
            return $outside;
        }

        return $line['cut'];
    }

    /** @param list<string> $pieces */
    private function elementFits(array $pieces, int $start): bool
    {
        $stack = [];
        $size = 0;
        $bytes = 0;
        for ($i = $start, $count = \count($pieces); $i < $count; ++$i) {
            $size += ($this->length)($pieces[$i]);
            $bytes += \strlen($pieces[$i]);
            if (!$this->fits($size, $bytes)) {
                return false;
            }
            $stack = self::nest($stack, $pieces[$i]);
            if ([] === $stack) {
                return true;
            }
        }

        return true;
    }

    private function fits(int $size, int $bytes): bool
    {
        return $size <= $this->limit && $bytes <= $this->maxBytes;
    }

    /** @param list<string> $pieces */
    private function size(array $pieces): int
    {
        return (int) \array_sum(\array_map($this->length, $pieces));
    }

    private static function isTag(string $piece): bool
    {
        return '<' === $piece[0] && '<' !== $piece;
    }

    private static function opens(string $piece): bool
    {
        return self::isTag($piece) && '/' !== $piece[1] && !\str_ends_with($piece, '/>') && 1 !== \preg_match('~^<(?:br|hr|img)\b~i', $piece);
    }

    /**
     * @param list<string> $open
     *
     * @return list<string> the open tags after the piece
     */
    private static function nest(array $open, string $piece): array
    {
        if (self::opens($piece)) {
            $open[] = $piece;
        } elseif ('</>' === $piece) {
            \array_pop($open);
        } elseif (\str_starts_with($piece, '</')) {
            for ($i = \count($open) - 1; $i >= 0; --$i) {
                if (self::tagName($open[$i]) === self::tagName($piece)) {
                    return \array_slice($open, 0, $i);
                }
            }
        }

        return $open;
    }

    /**
     * @param list<string> $open
     *
     * @return list<string>
     */
    private static function closing(array $open): array
    {
        return \array_map(static fn (string $tag): string => '</' . self::tagName($tag) . '>', \array_reverse($open));
    }

    private static function tagName(string $tag): string
    {
        return 1 === \preg_match('~^</?([a-z][\w-]*)~i', $tag, $m) ? \strtolower($m[1]) : '';
    }
}

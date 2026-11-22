<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\Max\MaxApiSender;

/**
 * A request text as the messages that carry it, in order. A text within the limit of the messenger is one message, byte for byte.
 * A longer one is cut after a line break, a line longer than a message between two characters, and never inside a tag or an entity.
 * An element that has to be cut is closed and opened again in the next message. Markup too long to repeat (an address of thousands of characters)
 * is sent as plain text, so nothing of it is lost. A message of only white space is left out: messengers refuse it.
 *
 * @internal
 */
final class MessageParts
{
    /** Telegram counts code points after it has read the HTML and dropped every \r (TDLib, MessageContent.cpp). */
    private const TELEGRAM_LIMIT = 4096;

    /** What a cut never divides: a tag, an entity, a line break, a character. */
    private const PIECE = '~</?[a-z][^<>]*>|&(?:\#[0-9]{1,8}|\#x[0-9a-f]{1,6}|[a-z][a-z0-9]{0,31});|\r\n|.~isu';

    /** @param non-empty-list<string> $texts */
    private function __construct(public readonly array $texts)
    {
    }

    public static function telegram(string $html): self
    {
        return new self(self::split($html, self::TELEGRAM_LIMIT, self::telegramLength(...)));
    }

    /** MAX counts code points of the HTML itself. */
    public static function max(string $html): self
    {
        return new self(self::split($html, MaxApiSender::TEXT_LIMIT, static fn (string $piece): int => \mb_strlen($piece)));
    }

    /** The reason of a refused message, with its number when the text needed several. */
    public function failure(int $index, string $reason): string
    {
        $total = \count($this->texts);

        return 1 === $total ? $reason : I18n::t('http.request_part_failed', ['part' => $index + 1, 'parts' => $total, 'reason' => $reason]);
    }

    /**
     * @param \Closure(string): int $length the length of one piece as the messenger counts it
     *
     * @return non-empty-list<string>
     */
    private static function split(string $html, int $limit, \Closure $length): array
    {
        if (\mb_strlen($html) <= $limit || false === \preg_match_all(self::PIECE, $html, $found)) {
            return [$html];
        }
        $pieces = $found[0];
        $lengths = \array_map($length, $pieces);
        if (\array_sum($lengths) <= $limit) {
            return [$html];
        }

        $texts = [];
        $open = []; // the tags that are open where the next message starts
        $count = \count($pieces);
        for ($from = 0; $from < $count; $from = $end) {
            $size = \array_sum(\array_map($length, $open));
            $stack = $open;
            $cut = null; // the last place that fits
            $line = null; // the last line break that fits
            $outside = null; // the same, outside any element: better, the element then stays whole
            $hasText = false;
            $endsLine = false;
            for ($i = $from; $i < $count; ++$i) {
                $size += $lengths[$i];
                if ($size > $limit && null !== $cut) {
                    break;
                }
                $piece = $pieces[$i];
                $stack = self::nest($stack, $piece);
                if (self::isTag($piece) && 4 * \max($lengths[$i], \array_sum(\array_map($length, $stack))) > 3 * $limit) {
                    return self::split(\strtr($html, ['<' => '&lt;', '>' => '&gt;']), $limit, $length); // no tags are left, so this ends
                }
                $hasText = $hasText || self::isText($piece);
                $endsLine = \str_ends_with($piece, "\n") || ($endsLine && \str_starts_with($piece, '</'));
                if ($i + 1 < $count && (self::opens($piece) || \str_starts_with($pieces[$i + 1], '</'))) {
                    continue; // never leaves an empty element on either side
                }
                $fits = $size + \array_sum(\array_map($length, self::closing($stack))) <= $limit;
                if (!$fits && null !== $cut) {
                    continue;
                }
                $cut = [$i + 1, $stack, $hasText];
                if (!$fits) {
                    break;
                }
                if ($endsLine && $hasText) {
                    $line = $cut;
                    if ([] === $stack) {
                        $outside = $cut;
                    }
                }
            }
            \assert(null !== $cut);
            [$end, $next, $visible] = $count === $cut[0] ? $cut : ($outside ?? $line ?? $cut);
            if ($visible) {
                $texts[] = \implode('', $open) . \implode('', \array_slice($pieces, $from, $end - $from)) . \implode('', self::closing($next));
            }
            $open = $next;
        }

        return [] !== $texts ? $texts : [$html];
    }

    private static function telegramLength(string $piece): int
    {
        return match (true) {
            self::isTag($piece), "\r" === $piece => 0,
            "\r\n" === $piece, 1 === \preg_match('~^&(?:\#\w+|amp|lt|gt|quot);$~', $piece) => 1,
            default => \mb_strlen($piece),
        };
    }

    private static function isTag(string $piece): bool
    {
        return '<' === $piece[0] && '<' !== $piece;
    }

    private static function opens(string $piece): bool
    {
        return self::isTag($piece) && '/' !== $piece[1] && !\str_ends_with($piece, '/>') && 1 !== \preg_match('~^<(?:br|hr|img)\b~i', $piece);
    }

    private static function isText(string $piece): bool
    {
        return !self::isTag($piece) && 1 === \preg_match('~[^\s\p{Z}\p{C}]~u', $piece);
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

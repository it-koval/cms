<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Request\PlainText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The two promises of PlainText on any input: no start of a tag, and nothing lost but tags. */
final class PlainTextTest extends TestCase
{
    /** @return iterable<string> */
    private static function mixes(): iterable
    {
        \mt_srand(20261122);
        $units = ['<', '>', '/', '=', '"', "'", ' ', "\n", "\t", "\0", "\u{A0}", "\xff", '!', '?', '-', 'b', 'p', 'a', 'x', 'S', '5', 'ж', '😀', '<b>', '</b>', '<br/>', 'script', ' href=x'];
        for ($case = 0; $case < 20000; ++$case) {
            $text = '';
            for ($i = \mt_rand(0, 24); $i > 0; --$i) {
                $text .= $units[\mt_rand(0, \count($units) - 1)];
            }
            yield $text;
        }
    }

    #[Test]
    public function noResultHoldsWhatStripTagsWouldTakeForATag(): void
    {
        foreach (self::mixes() as $text) {
            $plain = PlainText::from($text);

            self::assertSame($plain, \strip_tags($plain), \bin2hex($text));
        }
    }

    #[Test]
    public function textWithoutABracketComesOutAsFromStripTags(): void
    {
        foreach (self::mixes() as $text) {
            $text = \str_replace('<', '', $text);

            self::assertSame(\strip_tags($text), PlainText::from($text), \bin2hex($text));
        }
    }

    #[Test]
    public function whatStripTagsKeptIsNeverLost(): void
    {
        $bare = static fn (string $s): string => (string) \preg_replace('~[ \t\n\x0B\f\r<>]~', '', $s);
        foreach (self::mixes() as $text) {
            $kept = $bare(\strip_tags($text));
            $now = $bare(PlainText::from($text));
            $at = 0;
            $length = \strlen($now);
            for ($i = 0, $n = \strlen($kept); $i < $n; ++$i) {
                while ($at < $length && $now[$at] !== $kept[$i]) {
                    ++$at;
                }
                self::assertLessThan($length, $at, 'lost after the change: ' . \bin2hex($text));
                ++$at;
            }
        }
    }

    #[Test]
    public function aHugeHostileValueIsCleanedInLinearTime(): void
    {
        foreach (['<b a="', "<b a='", '<b ', '<p a=1', '<', '< ', '<b a="x" ', '<div a="<b a=\'">', '<b>'] as $unit) {
            $text = \str_repeat($unit, \intdiv(2_000_000, \strlen($unit)));
            $start = \hrtime(true);
            $plain = PlainText::from($text);

            self::assertLessThan(2.0, (\hrtime(true) - $start) / 1e9, $unit);
            self::assertDoesNotMatchRegularExpression('~<[^ \t\n\x0B\f\r]~', $plain, $unit);
        }
    }
}

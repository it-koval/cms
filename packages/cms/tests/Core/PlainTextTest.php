<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Request\PlainText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The promises of PlainText on any input: no start of a tag, nothing lost outside tags, and a bounded cost. */
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

            self::assertSame($plain . ' ', \strip_tags($plain . ' '), \bin2hex($text)); // a space: a "<" may be the last character
        }
    }

    #[Test]
    public function textWithoutABracketComesOutAsFromStripTags(): void
    {
        foreach (self::mixes() as $text) {
            $text = \str_replace('<', '', $text);

            self::assertSame(\trim(\strip_tags($text)), PlainText::from($text), \bin2hex($text));
        }
    }

    /** Outside tags: a ">" inside a quoted value belongs to the tag, also where strip_tags() ended a false tag there and kept what follows. */
    #[Test]
    public function whatStripTagsKeptOutsideTagsIsNeverLost(): void
    {
        $bare = static fn (string $s): string => (string) \preg_replace('~[ \t\n\x0B\f\r<>]~', '', $s);
        foreach (self::mixes() as $text) {
            $kept = $bare(\trim(\strip_tags($text)));
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
            foreach ([$text, $text . '>'] as $value) { // the last ">" switches off a shortcut of PCRE
                $start = \hrtime(true);
                $plain = PlainText::from($value, 1001);

                self::assertLessThan(2.0, (\hrtime(true) - $start) / 1e9, $unit);
                self::assertDoesNotMatchRegularExpression('~<[^ \t\n\x0B\f\r]~', $plain, $unit);
            }
        }
    }

    #[Test]
    public function aHugeValueIsCutBeforeItGrowsBySpaces(): void
    {
        $text = \str_repeat('<', 16_000_000);
        \memory_reset_peak_usage();
        $before = \memory_get_usage();

        $plain = PlainText::from($text, 1001);

        self::assertSame(1001, \mb_strlen($plain));
        self::assertLessThan(20_000_000, \memory_get_peak_usage() - $before, 'the value is never held twice as long');
    }

    #[Test]
    public function everyElementOfHtmlIsRemoved(): void
    {
        $elements = 'a abbr address area article aside audio b base bdi bdo blockquote body br button canvas caption cite code col colgroup data datalist dd del details '
            . 'dfn dialog div dl dt em embed fieldset figcaption figure footer form h1 h2 h3 h4 h5 h6 head header hgroup hr html i iframe img input ins kbd label legend '
            . 'li link main map mark math menu meta meter nav noscript object ol optgroup option output p picture pre progress q rp rt ruby s samp script search section '
            . 'select slot small source span strong style sub summary sup svg table tbody td template textarea tfoot th thead time title tr track u ul var video wbr';
        foreach (\explode(' ', $elements) as $element) {
            self::assertSame('Шлем', PlainText::from("<$element class=\"x\">Шлем</$element>"), $element);
        }
    }
}

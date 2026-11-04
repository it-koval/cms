<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * Ready critical CSS of one page: one block per media range. Separate blocks instead of one union because each crittr run returns its rules
 * in source order, and concatenating two runs can put a rule after one that followed it in the stylesheet, which changes the cascade.
 * Adjacent ranges with identical CSS are merged into one block (the usual case on simple pages), so the duplicate bytes are paid only when the screens differ.
 */
final readonly class CriticalStyles
{
    /** @param non-empty-list<array{media: string|null, css: string}> $blocks */
    private function __construct(public array $blocks)
    {
    }

    /**
     * From the CSS of each run, in viewport order. Returns the reason instead of an object when the result must not be stored:
     * an empty run (crittr found nothing: a broken page or a selector bug, never a reason to inline nothing) or one above $maxBytes.
     *
     * @param list<Viewport> $viewports sorted
     * @param list<string> $cssPerViewport same length and order
     */
    public static function fromRuns(array $viewports, array $cssPerViewport, int $maxBytes): self|string
    {
        if (\count($viewports) !== \count($cssPerViewport) || [] === $viewports) {
            return 'bad output';
        }
        foreach ($cssPerViewport as $css) {
            if ('' === \trim($css)) {
                return 'empty';
            }
            if (\strlen($css) > $maxBytes) {
                return 'too large';
            }
        }
        $ranges = Viewport::mediaRanges($viewports);
        $runs = [];
        foreach ($cssPerViewport as $i => $css) {
            $runs[] = ['from' => $i, 'to' => $i, 'css' => $css];
        }
        $merged = [];
        foreach ($runs as $run) {
            $last = \count($merged) - 1;
            if ($last >= 0 && $merged[$last]['css'] === $run['css']) {
                $merged[$last]['to'] = $run['to'];
            } else {
                $merged[] = $run;
            }
        }
        $blocks = [];
        foreach ($merged as $run) {
            $blocks[] = ['media' => self::range($ranges, $run['from'], $run['to']), 'css' => $run['css']];
        }

        return new self($blocks);
    }

    /**
     * The range of the first merged viewport widened to the last one: the lower bound of the first and the upper bound of the last.
     *
     * @param list<string|null> $ranges
     */
    private static function range(array $ranges, int $from, int $to): ?string
    {
        $lower = self::bound($ranges[$from], 'min');
        $upper = self::bound($ranges[$to], 'max');
        $parts = \array_values(\array_filter([$lower, $upper], static fn (?string $p): bool => null !== $p));

        return [] === $parts ? null : \implode(' and ', $parts);
    }

    private static function bound(?string $range, string $kind): ?string
    {
        if (null === $range || 1 !== \preg_match('~\(' . $kind . '-width:[^)]+\)~', $range, $m)) {
            return null;
        }

        return $m[0];
    }

    /** @param non-empty-list<array{media: string|null, css: string}> $stored the JSON column of a ready row (written by toStored(), so trusted) */
    public static function fromStored(array $stored): self
    {
        return new self($stored);
    }

    /** @return non-empty-list<array{media: string|null, css: string}> */
    public function toStored(): array
    {
        return $this->blocks;
    }
}

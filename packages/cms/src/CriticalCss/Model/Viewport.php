<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/** One crittr run: the window the first screen is measured in. Built from config (validated there), so the numbers are trusted here. */
final readonly class Viewport
{
    public function __construct(
        public int $width,
        public int $height,
        public bool $mobile = false,
    ) {
    }

    /** @return array{width: int, height: int, mobile: bool} what the Node script and the config stamp get */
    public function toArray(): array
    {
        return ['width' => $this->width, 'height' => $this->height, 'mobile' => $this->mobile];
    }

    /**
     * The media range each viewport's CSS is inlined for, derived from the sorted widths alone (no second list to keep in step):
     * a viewport covers screens from its own width up to the next viewport's width; the narrowest also covers everything below it.
     *   [390, 1280]       => ['(max-width:1279.98px)', '(min-width:1280px)']
     *   [390, 768, 1280]  => ['(max-width:767.98px)', '(min-width:768px) and (max-width:1279.98px)', '(min-width:1280px)']
     *   [1280]            => [null]  (no media attribute)
     *
     * @param list<self> $sorted by width, ascending, distinct widths (the config node guarantees it)
     *
     * @return list<string|null>
     */
    public static function mediaRanges(array $sorted): array
    {
        $ranges = [];
        $last = \count($sorted) - 1;
        foreach ($sorted as $i => $viewport) {
            $parts = [];
            if ($i > 0) {
                $parts[] = '(min-width:' . $viewport->width . 'px)';
            }
            if ($i < $last) {
                $parts[] = '(max-width:' . \number_format($sorted[$i + 1]->width - 0.02, 2, '.', '') . 'px)';
            }
            $ranges[] = [] === $parts ? null : \implode(' and ', $parts);
        }

        return $ranges;
    }
}

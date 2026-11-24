<?php

declare(strict_types=1);

namespace Itk\Cms\Admin\Theme;

/**
 * Colour maths for the admin theme: hex <-> OKLCH (Björn Ottosson's OKLab) and the WCAG contrast ratio.
 * The same formulas are used by admin/src/utils/colorTools.js for the picker's own readout; the palette itself is only ever made here.
 *
 * @internal
 */
final class Color
{
    /** '#RGB', 'RGB', '#RRGGBB' or 'RRGGBB' -> '#RRGGBB' in capitals, or null when it is not a colour. */
    public static function normalize(string $hex): ?string
    {
        $hex = \ltrim(\trim($hex), '#');
        if (1 === \preg_match('/^[0-9a-f]{3}$/i', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return 1 === \preg_match('/^[0-9a-f]{6}$/i', $hex) ? '#' . \strtoupper($hex) : null;
    }

    /** @return array{0: float, 1: float, 2: float} lightness 0..1, chroma ~0..0.4, hue in degrees 0..360 */
    public static function toOklch(string $hex): array
    {
        [$r, $g, $b] = self::linear($hex);
        $l = self::cbrt(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
        $m = self::cbrt(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
        $s = self::cbrt(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);
        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bb = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
        $chroma = \sqrt($a * $a + $bb * $bb);
        $hue = $chroma < 1e-4 ? 0.0 : \fmod(\rad2deg(\atan2($bb, $a)) + 360.0, 360.0);

        return [$lightness, $chroma, $hue];
    }

    /** The colour for L / C / h; a chroma the screen cannot show is lowered until it fits, the lightness and hue stay. */
    public static function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        $lightness = \max(0.0, \min(1.0, $lightness));
        $chroma = \max(0.0, $chroma);
        $rgb = self::rgbOf($lightness, $chroma, $hue);
        if (!self::inGamut($rgb)) {
            $low = 0.0;
            $high = $chroma;
            for ($i = 0; $i < 24; ++$i) {
                $mid = ($low + $high) / 2;
                if (self::inGamut(self::rgbOf($lightness, $mid, $hue))) {
                    $low = $mid;
                } else {
                    $high = $mid;
                }
            }
            $rgb = self::rgbOf($lightness, $low, $hue);
        }

        return \sprintf('#%02X%02X%02X', ...\array_map(static fn (float $c): int => (int) \round(255 * self::gamma(\max(0.0, \min(1.0, $c)))), $rgb));
    }

    /** Relative luminance of WCAG 2: 0 (black) .. 1 (white). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::linear($hex);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Contrast ratio of two colours, 1..21 (text needs 4.5 for level AA). */
    public static function contrast(string $a, string $b): float
    {
        $x = self::luminance($a);
        $y = self::luminance($b);

        return (\max($x, $y) + 0.05) / (\min($x, $y) + 0.05);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function linear(string $hex): array
    {
        $hex = self::normalize($hex) ?? '#000000';

        return \array_map(static function (string $pair): float {
            $c = \hexdec($pair) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [\substr($hex, 1, 2), \substr($hex, 3, 2), \substr($hex, 5, 2)]);
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }

    private static function gamma(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB, may leave 0..1 */
    private static function rgbOf(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * \cos(\deg2rad($hue));
        $b = $chroma * \sin(\deg2rad($hue));
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $c) {
            if ($c < -0.0005 || $c > 1.0005) {
                return false;
            }
        }

        return true;
    }
}

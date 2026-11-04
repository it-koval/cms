<?php

declare(strict_types=1);

namespace Itk\Cms\Admin\Theme;

/**
 * The 50..950 ladder of a colour, made in OKLCH: the hue stays, the lightness walks a fixed ladder, the chroma follows the brand's own.
 * The step nearest to the brand's lightness is the brand itself, so a picked colour is always on its ladder.
 */
final class Palette
{
    public const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /** Text that sits on a brand surface: white, or the admin's own ink when white does not read. */
    public const WHITE = '#FFFFFF';
    public const DARK = '#14181C';

    /** The colour of a tinted surface (cards, chips); text on it must read too. */
    public const MIN_TEXT = 4.5;

    private const LIGHTNESS = [50 => 0.965, 100 => 0.925, 200 => 0.82, 300 => 0.74, 400 => 0.66, 500 => 0.58, 600 => 0.51, 700 => 0.44, 800 => 0.37, 900 => 0.30, 950 => 0.23];
    private const CHROMA = [50 => 0.14, 100 => 0.28, 200 => 0.55, 300 => 0.75, 400 => 0.92, 500 => 1.0, 600 => 1.0, 700 => 1.0, 800 => 0.92, 900 => 0.8, 950 => 0.65];

    /**
     * @return array<int, string> step => '#RRGGBB'
     */
    public static function ladder(string $hex, bool $anchored = true): array
    {
        $hex = Color::normalize($hex) ?? '#000000';
        [$lightness, $chroma, $hue] = Color::toOklch($hex);
        $out = [];
        foreach (self::STEPS as $step) {
            $out[$step] = Color::fromOklch(self::LIGHTNESS[$step], $chroma * self::CHROMA[$step], $hue);
        }
        if ($anchored) {
            $nearest = self::STEPS[0];
            foreach (self::STEPS as $step) {
                if (\abs(self::LIGHTNESS[$step] - $lightness) < \abs(self::LIGHTNESS[$nearest] - $lightness)) {
                    $nearest = $step;
                }
            }
            $out[$nearest] = $hex;
        }

        return $out;
    }

    /** The text colour for a surface of this colour: white when it reads (4.5), else the dark ink, else whichever reads better. */
    public static function ink(string $hex): string
    {
        $white = Color::contrast($hex, self::WHITE);
        if ($white >= self::MIN_TEXT) {
            return self::WHITE;
        }

        return Color::contrast($hex, self::DARK) > $white ? self::DARK : self::WHITE;
    }

    /** The best contrast any text colour of the admin can reach on this colour. */
    public static function bestContrast(string $hex): float
    {
        return \max(Color::contrast($hex, self::WHITE), Color::contrast($hex, self::DARK));
    }

    /**
     * The colour itself as text on the given light surfaces, darkened (same hue and chroma) until it reaches `$min` on all of them.
     *
     * @param list<string> $surfaces
     */
    public static function textOn(string $hex, array $surfaces, float $min = self::MIN_TEXT): string
    {
        [$lightness, $chroma, $hue] = Color::toOklch($hex);
        $current = Color::normalize($hex) ?? '#000000';
        while ($lightness > 0.0 && !self::reads($current, $surfaces, $min)) {
            $lightness = \max(0.0, $lightness - 0.01);
            $current = Color::fromOklch($lightness, $chroma, $hue);
        }

        return $current;
    }

    /**
     * The colour moved lighter (or darker) in steps of 0.01 lightness until it reaches `$min` on the surface.
     */
    public static function readableOn(string $hex, string $surface, float $min, bool $lighter): string
    {
        [$lightness, $chroma, $hue] = Color::toOklch($hex);
        $current = Color::normalize($hex) ?? '#000000';
        while (Color::contrast($current, $surface) < $min && $lightness > 0.0 && $lightness < 1.0) {
            $lightness = \max(0.0, \min(1.0, $lightness + ($lighter ? 0.01 : -0.01)));
            $current = Color::fromOklch($lightness, $chroma, $hue);
        }

        return $current;
    }

    /** @param list<string> $surfaces */
    private static function reads(string $hex, array $surfaces, float $min): bool
    {
        foreach ($surfaces as $surface) {
            if (Color::contrast($hex, $surface) < $min) {
                return false;
            }
        }

        return true;
    }
}

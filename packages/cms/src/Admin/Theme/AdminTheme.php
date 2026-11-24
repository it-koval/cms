<?php

declare(strict_types=1);

namespace Itk\Cms\Admin\Theme;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Setting;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The colours and corners of the admin. The developer sets the defaults in the bundle config (itk_cms.admin.theme: brand, accent, radius, logo, name);
 * an administrator can override brand, accent and radius in «Настройки → Оформление админки». The override is the setting `adminTheme` (shared by all languages).
 *
 * The theme becomes CSS variables (vars()): the shell page puts them into a <style> before the admin starts, so there is no flash of the old colours,
 * and the admin sets the same variables on the page after a save. The palette 50..950 and every derived colour are made here, in OKLCH (Palette).
 * The values that reach the CSS are only checked hex colours and whole numbers.
 */
final class AdminTheme
{
    public const KEY = 'itk:adminTheme';
    public const DEFAULTS = ['brand' => '#0B5D80', 'accent' => '#C8217A', 'radius' => 6];
    public const MAX_RADIUS = 16;
    public const MAX_NAME = 40;

    /** The hand-tuned set of the admin's own colours: the default brand and accent keep exactly the look the admin was designed with. */
    private const HAND_BRAND = ['d' => '#084A66', 'dd' => '#063A51', '50' => '#E8F3F8', '100' => '#CFE6F0', '200' => '#9CCAE0', 'rail' => '#D2E8F2', 'rail2' => '#A9CFE1'];
    private const HAND_ACCENT = ['d' => '#A3185F', '50' => '#FCEEF6', '100' => '#F6D2E5'];

    /** @param array{brand?: string, accent?: string, radius?: int, logo?: ?string, name?: ?string} $config */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingRepository $settings,
        #[Autowire(param: 'itk_cms.admin.theme')]
        private readonly array $config = [],
    ) {
    }

    /**
     * What the developer set (or the built-in look): { brand, accent, radius }.
     *
     * @return array{brand: string, accent: string, radius: int}
     */
    public function defaults(): array
    {
        return [
            'brand' => Color::normalize((string) ($this->config['brand'] ?? '')) ?? self::DEFAULTS['brand'],
            'accent' => Color::normalize((string) ($this->config['accent'] ?? '')) ?? self::DEFAULTS['accent'],
            'radius' => \max(0, \min(self::MAX_RADIUS, (int) ($this->config['radius'] ?? self::DEFAULTS['radius']))),
        ];
    }

    public function logo(): ?string
    {
        $logo = \trim((string) ($this->config['logo'] ?? ''));

        return '' !== $logo && 1 === \preg_match('~^(/(?!/)|https://)[^\s"\'<>\\\\]+$~', $logo) ? $logo : null;
    }

    /** The product name in the header of the admin instead of «ITK CMS» (a short line of plain text), or null for the built-in one. */
    public function name(): ?string
    {
        $name = \trim((string) ($this->config['name'] ?? ''));

        return '' !== $name && \mb_strlen($name) <= self::MAX_NAME && 1 !== \preg_match('/[\x00-\x1F\x7F]/', $name) ? $name : null;
    }

    /** Has an administrator changed the look? */
    public function isCustom(): bool
    {
        return null !== $this->stored();
    }

    /**
     * The look in force: the override, field by field over the defaults.
     *
     * @return array{brand: string, accent: string, radius: int}
     */
    public function effective(): array
    {
        $theme = $this->defaults();
        $stored = $this->stored() ?? [];
        if (null !== ($brand = Color::normalize((string) ($stored['brand'] ?? '')))) {
            $theme['brand'] = $brand;
        }
        if (null !== ($accent = Color::normalize((string) ($stored['accent'] ?? '')))) {
            $theme['accent'] = $accent;
        }
        if (isset($stored['radius']) && \is_numeric($stored['radius'])) {
            $theme['radius'] = \max(0, \min(self::MAX_RADIUS, (int) $stored['radius']));
        }

        return $theme;
    }

    /**
     * The look as the person typed it, checked. Missing keys keep the look in force.
     * `$strict` also refuses a colour no text can be read on (what a save does); the live preview only needs the colours to be colours.
     *
     * @param array<string, mixed> $input
     *
     * @return array{brand: string, accent: string, radius: int}
     */
    public function sanitize(array $input, bool $strict = true): array
    {
        $theme = $this->effective();
        $errors = [];
        foreach (['brand' => 'http.theme_unreadable_brand', 'accent' => 'http.theme_unreadable_accent'] as $key => $unreadable) {
            if (!\array_key_exists($key, $input)) {
                continue;
            }
            $color = \is_string($input[$key]) ? Color::normalize($input[$key]) : null;
            if (null === $color) {
                $errors[$key] = I18n::t('http.theme_color_hint');
            } elseif ($strict && Palette::bestContrast($color) < Palette::MIN_TEXT) {
                $errors[$key] = I18n::t($unreadable);
            } else {
                $theme[$key] = $color;
            }
        }
        if (\array_key_exists('radius', $input)) {
            $radius = $input['radius'];
            if (!\is_int($radius) && !(\is_string($radius) && 1 === \preg_match('/^\d{1,2}$/', $radius))) {
                $errors['radius'] = I18n::t('http.theme_radius', ['max' => self::MAX_RADIUS]);
            } elseif ((int) $radius < 0 || (int) $radius > self::MAX_RADIUS) {
                $errors['radius'] = I18n::t('http.theme_radius', ['max' => self::MAX_RADIUS]);
            } else {
                $theme['radius'] = (int) $radius;
            }
        }
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('http.theme_check_colors'), $errors);
        }

        return $theme;
    }

    /**
     * Saves the look. A look equal to the developer's defaults is the same as no override.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> payload()
     */
    public function save(array $input): array
    {
        $theme = $this->sanitize($input);
        if ($theme === $this->defaults()) {
            $this->reset();

            return $this->payload();
        }
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);
        if (null === $row) {
            $this->em->persist(new Setting(self::KEY, Setting::ALL, $theme));
        } else {
            $row->value = $theme;
        }
        $this->em->flush();

        return $this->payload();
    }

    /** Back to the developer's look. */
    public function reset(): void
    {
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);
        if (null !== $row) {
            $this->em->remove($row);
            $this->em->flush();
        }
    }

    /**
     * What the admin's settings screen reads and what a save or a preview answers with.
     *
     * @param array{brand: string, accent: string, radius: int}|null $theme a look that is not saved (the preview); null = the look in force
     *
     * @return array{theme: array<string, mixed>, custom: bool, defaults: array<string, mixed>, vars: array<string, string>, checks: array<string, array<string, mixed>>, ladder: array<string, array<int, string>>}
     */
    public function payload(?array $theme = null): array
    {
        $own = null === $theme;
        $theme ??= $this->effective();

        return [
            'theme' => $theme + ['logo' => $this->logo()],
            'custom' => $own ? $this->isCustom() : $theme !== $this->defaults(),
            'defaults' => $this->defaults(),
            'vars' => $this->vars($theme),
            'checks' => ['brand' => self::check($theme['brand']), 'accent' => self::check($theme['accent'])],
            'ladder' => ['brand' => Palette::ladder($theme['brand']), 'accent' => Palette::ladder($theme['accent'])],
        ];
    }

    /**
     * What the shell page needs: the look for window.ITK_ADMIN.theme and the <style> with its variables.
     *
     * @return array{theme: array<string, mixed>, css: string}
     */
    public function shell(): array
    {
        $theme = $this->effective();
        $vars = $this->vars($theme);

        // a new key goes last: the tests of a site compare the start of this object byte by byte
        return [
            'theme' => $theme + ['logo' => $this->logo(), 'custom' => $this->isCustom(), 'vars' => $vars, 'name' => $this->name()],
            'css' => self::css($vars),
        ];
    }

    /**
     * The few variables the editor's preview frame needs (its overlay lives inside the site's own page, which knows nothing of the admin's :root):
     * the outline of the chosen field, the mark of a changed one. See public/preview.css.
     */
    public function previewCss(): string
    {
        $vars = $this->vars($this->effective());

        return self::css([
            '--itk-brand' => $vars['--brand'], '--itk-brand-50' => $vars['--cy-50'], '--itk-brand-text' => $vars['--brand-text'],
            '--itk-brand-text-d' => $vars['--brand-text-d'], '--itk-accent' => $vars['--accent'],
        ]);
    }

    /** @param array<string, string> $vars */
    public static function css(array $vars): string
    {
        $rules = [];
        foreach ($vars as $name => $value) {
            $rules[] = $name . ':' . $value;
        }

        return ':root{' . \implode(';', $rules) . '}';
    }

    /**
     * Can text be read on this colour? { ink, ratio, level: AAA|AA|fail, ok, white, dark, text, textAdjusted }
     * (`text` = the colour itself as text on white, darkened when it would not read).
     *
     * @return array<string, mixed>
     */
    public static function check(string $hex): array
    {
        $ink = Palette::ink($hex);
        $ratio = Color::contrast($hex, $ink);
        $text = Palette::textOn($hex, [Palette::WHITE]);

        return [
            'ink' => $ink,
            'ratio' => \round($ratio, 2),
            'level' => $ratio >= 7 ? 'AAA' : ($ratio >= Palette::MIN_TEXT ? 'AA' : 'fail'),
            'ok' => $ratio >= Palette::MIN_TEXT,
            'white' => \round(Color::contrast($hex, Palette::WHITE), 2),
            'dark' => \round(Color::contrast($hex, Palette::DARK), 2),
            'text' => $text,
            'textAdjusted' => $text !== $hex,
        ];
    }

    /**
     * The CSS variables of a look. Names: the semantic ones (--brand, --brand-ink, --brand-text, --accent, ..., --brand-50..950) for new code
     * and the short ones the admin's styles were written with (--cy*, --mag*, --rail-ink*). Set on :root they restyle the whole admin;
     * set on one element (the preview) they restyle only it, which is why every name is listed.
     *
     * @param array{brand: string, accent: string, radius: int} $theme
     *
     * @return array<string, string>
     */
    public function vars(array $theme): array
    {
        $b = $this->brandColors($theme['brand']);
        $a = $this->accentColors($theme['accent']);
        $vars = [
            '--cy' => $theme['brand'], '--cy-d' => $b['d'], '--cy-dd' => $b['dd'], '--cy-50' => $b['50'], '--cy-100' => $b['100'], '--cy-200' => $b['200'],
            '--rail-ink' => $b['rail'], '--rail-ink-2' => $b['rail2'], '--rail-shade' => $b['shade'], '--rail-shade-2' => $b['shade2'],
            '--brand' => $theme['brand'], '--brand-ink' => $b['ink'], '--brand-text' => $b['text'], '--brand-text-d' => $b['textD'],
            '--mag' => $theme['accent'], '--mag-d' => $a['d'], '--mag-50' => $a['50'], '--mag-100' => $a['100'],
            '--accent' => $theme['accent'], '--accent-ink' => $a['ink'], '--accent-text' => $a['text'], '--accent-text-d' => $a['textD'],
        ];
        foreach (Palette::ladder($theme['brand']) as $step => $hex) {
            $vars['--brand-' . $step] = $hex;
        }
        foreach (Palette::ladder($theme['accent']) as $step => $hex) {
            $vars['--accent-' . $step] = $hex;
        }
        $vars['--r'] = $theme['radius'] . 'px';
        $vars['--r-lg'] = ($theme['radius'] + 4) . 'px';

        return $vars;
    }

    /** @return array<int|string, string> named colours and the numbered steps of the ladder (PHP turns the key '50' into the number 50) */
    private function brandColors(string $hex): array
    {
        $ink = Palette::ink($hex);
        $raw = Palette::ladder($hex, false);
        if (self::DEFAULTS['brand'] === $hex) {
            $hand = self::HAND_BRAND;

            return ['d' => $hand['d'], 'dd' => $hand['dd'], '50' => $hand['50'], '100' => $hand['100'], '200' => $hand['200'], 'rail' => $hand['rail'], 'rail2' => $hand['rail2'],
                'ink' => $ink, 'text' => $hex, 'textD' => $hand['d'], 'shade' => 'rgba(0,0,0,.2)', 'shade2' => 'rgba(0,0,0,.3)'];
        }
        [$l, $c, $h] = Color::toOklch($hex);
        $light = Palette::WHITE === $ink;
        $d = Palette::readableOn(Color::fromOklch($l - 0.06, $c, $h), $ink, Palette::MIN_TEXT, !$light);
        $dd = Palette::textOn(Color::fromOklch($l - 0.12, $c, $h), [$raw[50]], 7.0);
        if ($light) {
            $rail = Palette::readableOn(Color::fromOklch(0.92, $c * 0.18, $h), $hex, Palette::MIN_TEXT, true);
            $rail2 = Palette::readableOn(Color::fromOklch(0.82, $c * 0.32, $h), $hex, Palette::MIN_TEXT, true);
        } else {
            $rail = Palette::readableOn(Color::fromOklch(0.25, $c * 0.3, $h), $hex, 7.0, false);
            $rail2 = Palette::readableOn(Color::fromOklch(0.33, $c * 0.35, $h), $hex, Palette::MIN_TEXT, false);
        }

        return [
            'd' => $d, 'dd' => $dd, '50' => $raw[50], '100' => $raw[100], '200' => $raw[200], 'rail' => $rail, 'rail2' => $rail2,
            'ink' => $ink, 'text' => Palette::textOn($hex, [Palette::WHITE, $raw[50]]), 'textD' => Palette::textOn($d, [Palette::WHITE, $raw[50]]),
            'shade' => $light ? 'rgba(0,0,0,.2)' : 'rgba(20,24,28,.12)', 'shade2' => $light ? 'rgba(0,0,0,.3)' : 'rgba(20,24,28,.2)',
        ];
    }

    /** @return array<int|string, string> named colours and the numbered steps of the ladder (PHP turns the key '50' into the number 50) */
    private function accentColors(string $hex): array
    {
        $ink = Palette::ink($hex);
        $raw = Palette::ladder($hex, false);
        if (self::DEFAULTS['accent'] === $hex) {
            $hand = self::HAND_ACCENT;

            return ['d' => $hand['d'], '50' => $hand['50'], '100' => $hand['100'], 'ink' => $ink, 'text' => $hex, 'textD' => $hand['d']];
        }
        [$l, $c, $h] = Color::toOklch($hex);

        $d = Palette::readableOn(Color::fromOklch($l - 0.06, $c, $h), $ink, Palette::MIN_TEXT, Palette::WHITE !== $ink);

        return [
            'd' => $d, '50' => $raw[50], '100' => $raw[100], 'ink' => $ink,
            'text' => Palette::textOn($hex, [Palette::WHITE, $raw[50]]), 'textD' => Palette::textOn($d, [Palette::WHITE, $raw[50]]),
        ];
    }

    /** @return array<string, mixed>|null */
    private function stored(): ?array
    {
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);

        return null !== $row && \is_array($row->value) ? $row->value : null;
    }
}

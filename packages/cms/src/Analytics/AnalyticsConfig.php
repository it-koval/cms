<?php

declare(strict_types=1);

namespace Itk\Cms\Analytics;

/**
 * The counters and verification codes of the site, parsed from the settings of the group «Аналитика и вебмастера». A value that does not parse gives nothing on the site
 * and its settings key lands in $problems (the health line names it). Everything that reaches the page has passed these patterns, so none of it can carry markup.
 *
 * @internal
 */
final class AnalyticsConfig
{
    public const METRIKA_ID = 'yandexMetrikaId';
    public const METRIKA_WEBVISOR = 'yandexMetrikaWebvisor';
    public const GA_ID = 'googleAnalyticsId';
    public const YANDEX_VERIFICATION = 'yandexVerification';
    public const GOOGLE_VERIFICATION = 'googleVerification';

    /**
     * @param list<string> $problems settings keys whose value is filled but not valid
     */
    private function __construct(
        public readonly ?string $metrikaId,
        public readonly bool $webvisor,
        public readonly ?string $googleAnalyticsId,
        public readonly ?string $yandexVerification,
        public readonly ?string $googleVerification,
        public readonly array $problems,
    ) {
    }

    /** @param array<string, mixed> $settings the flat settings (SettingsService::all()) */
    public static function fromSettings(array $settings): self
    {
        $problems = [];
        $metrika = self::match($settings, self::METRIKA_ID, '/^\d{4,12}$/', $problems);
        $ga = self::match($settings, self::GA_ID, '/^G-[A-Z0-9]{4,16}$/', $problems, \strtoupper(...));
        $yandex = self::match($settings, self::YANDEX_VERIFICATION, '/^[A-Za-z0-9_\-]{8,120}$/', $problems, self::metaContent(...));
        $google = self::match($settings, self::GOOGLE_VERIFICATION, '/^[A-Za-z0-9_\-]{8,120}$/', $problems, self::metaContent(...));
        $webvisor = \filter_var($settings[self::METRIKA_WEBVISOR] ?? false, \FILTER_VALIDATE_BOOLEAN);

        return new self($metrika, null !== $metrika && $webvisor, $ga, $yandex, $google, $problems);
    }

    /** Is there anything to put on the page? */
    public function isEmpty(): bool
    {
        return null === $this->metrikaId && null === $this->googleAnalyticsId && null === $this->yandexVerification && null === $this->googleVerification;
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<string> $problems
     * @param ?\Closure(string): string $normalize
     */
    private static function match(array $settings, string $key, string $pattern, array &$problems, ?\Closure $normalize = null): ?string
    {
        $raw = $settings[$key] ?? null;
        $value = \is_scalar($raw) ? \trim((string) $raw) : '';
        if ('' === $value) {
            return null;
        }
        if (null !== $normalize) {
            $value = $normalize($value);
        }
        if (1 !== \preg_match($pattern, $value)) {
            $problems[] = $key;

            return null;
        }

        return $value;
    }

    /** The owner may paste the whole `<meta name="…" content="…">` of Webmaster; its content attribute is the code. */
    private static function metaContent(string $value): string
    {
        if (!\str_starts_with($value, '<')) {
            return $value;
        }
        if (1 === \preg_match('/<meta\b[^>]*?\bcontent\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>\/]+))/i', $value, $m)) {
            return \trim($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? ''));
        }

        return $value;
    }
}

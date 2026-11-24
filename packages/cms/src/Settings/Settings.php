<?php

declare(strict_types=1);

namespace Itk\Cms\Settings;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The site settings for code (controllers, services, commands): a value is cast to the type of its field in the schema.
 *
 *   $settings->string('phone')         $settings->bool('maintenance')        $settings->int('seats', 10)
 *   $settings->array('socials')        $settings->media('logo')              $settings->get('siteName', locale: 'en')
 *
 * Twig: settings('phone') is the same. A setting of a field marked translatable() is read in the language you ask for (default: the main one).
 * A default is used when the setting is not in the schema or is empty (an empty text / number / list); false and 0 are real values.
 */
final class Settings
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Schema $schema,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * Every setting of a language, each cast to the type of its field.
     *
     * @return array<string, mixed>
     */
    public function all(?string $locale = null): array
    {
        $out = [];
        foreach ($this->settings->all($locale ?? $this->mainLocale) as $key => $value) {
            $out[$key] = $this->cast((string) $key, $value);
        }

        return $out;
    }

    /** The value cast to the type of its field (toggle → bool, number → int|float, text → string, image / link / repeater → array). */
    public function get(string $key, mixed $default = null, ?string $locale = null): mixed
    {
        $value = $this->cast($key, $this->raw($key, $locale));

        return $this->isEmpty($value) ? $default : $value;
    }

    public function string(string $key, string $default = '', ?string $locale = null): string
    {
        $value = $this->raw($key, $locale);

        return \is_scalar($value) && '' !== (string) $value ? (\is_bool($value) ? ($value ? '1' : '') : (string) $value) : $default;
    }

    public function int(string $key, int $default = 0, ?string $locale = null): int
    {
        $value = $this->raw($key, $locale);

        return \is_numeric($value) || \is_bool($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false, ?string $locale = null): bool
    {
        $value = $this->raw($key, $locale);

        return null === $value || '' === $value ? $default : \filter_var($value, \FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<int|string, mixed> $default
     *
     * @return array<int|string, mixed>
     */
    public function array(string $key, array $default = [], ?string $locale = null): array
    {
        $value = $this->raw($key, $locale);

        return \is_array($value) && [] !== $value ? $value : $default;
    }

    /** A picture setting: { src, alt }, or null while none is chosen. @return null|array{src: string, alt: string} */
    public function media(string $key, ?string $locale = null): ?array
    {
        $value = $this->raw($key, $locale);
        if (!\is_array($value) || '' === \trim((string) ($value['src'] ?? ''))) {
            return null;
        }

        return ['src' => (string) $value['src'], 'alt' => (string) ($value['alt'] ?? '')];
    }

    /**
     * What is saved, else the initial value of the field, else null (so a default of the caller applies). Keys that are not in the schema
     * (tgOn, forms …) read as the settings screen does.
     */
    private function raw(string $key, ?string $locale): mixed
    {
        $locale ??= $this->mainLocale;
        $field = $this->schema->settingsField($key);
        if (null === $field) {
            return $this->settings->get($key, $locale);
        }
        if ($this->settings->isSet($key, $locale)) {
            return $this->settings->get($key, $locale);
        }

        return $field->hasDefault() ? $field->defaultValue() : ('select' === $field->type() ? $this->settings->get($key, $locale) : null);
    }

    private function cast(string $key, mixed $value): mixed
    {
        $field = $this->schema->settingsField($key);
        if (null === $field) {
            return $value;
        }

        return match ($field->type()) {
            'toggle' => null === $value || '' === $value ? false : \filter_var($value, \FILTER_VALIDATE_BOOLEAN),
            'number' => \is_numeric($value) ? $value + 0 : null,
            'text', 'textarea', 'headline', 'select' => \is_scalar($value) ? (\is_bool($value) ? ($value ? '1' : '') : (string) $value) : '',
            'image' => \is_array($value) ? ['src' => (string) ($value['src'] ?? ''), 'alt' => (string) ($value['alt'] ?? '')] : ['src' => '', 'alt' => ''],
            'link', 'repeater', 'photos' => \is_array($value) ? $value : [],
            default => $value,
        };
    }

    /** Nothing was entered: null, an empty text, an empty list, a picture without an address. */
    private function isEmpty(mixed $value): bool
    {
        if (\is_array($value) && \array_key_exists('src', $value)) {
            return '' === \trim((string) $value['src']);
        }

        return null === $value || '' === $value || [] === $value;
    }
}

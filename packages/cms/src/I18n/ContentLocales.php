<?php

declare(strict_types=1);

namespace Itk\Cms\I18n;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Support\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The site's content languages, their address prefixes and language metadata. */
final class ContentLocales
{
    private const NAMES = ['ru' => 'Русский', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano', 'pl' => 'Polski', 'uk' => 'Українська', 'kk' => 'Қазақша', 'tr' => 'Türkçe', 'zh' => '中文']; // i18n-ignore: languages are named in themselves

    /** @param array<string, array{name: string, html_lang?: ?string, og_locale?: ?string}>|null $locales */
    public function __construct(
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        #[Autowire(param: 'itk_cms.locales')]
        private readonly ?array $locales = null,
        #[Autowire(param: 'itk_cms.locales_configured')]
        private readonly ?bool $configured = null,
    ) {
    }

    /** The main content language, whose addresses have no language prefix. */
    public function main(): string
    {
        return $this->mainLocale;
    }

    /** @return list<string> The configured codes with the main language first. */
    public function codes(): array
    {
        return \array_map('strval', \array_keys([$this->mainLocale => true] + ($this->locales ?? [])));
    }

    /** @return array{main: string, list: list<array{code: string, name: string}>} The main code and display names for language selectors. */
    public function catalog(): array
    {
        return [
            'main' => $this->main(),
            'list' => \array_map(fn (string $code): array => ['code' => $code, 'name' => $this->name($code)], $this->codes()),
        ];
    }

    /** Whether the site explicitly configured its content languages. */
    public function isConfigured(): bool
    {
        return $this->configured ?? (null !== $this->locales);
    }

    /** Whether the site serves more than one content language. */
    public function isMultilingual(): bool
    {
        return $this->isConfigured() && \count($this->codes()) >= 2;
    }

    /** Whether a code is the main language or appears in the configured list. */
    public function has(string $code): bool
    {
        return $code === $this->mainLocale || isset($this->locales[$code]);
    }

    /** Whether routing serves this language version at its published path. */
    public function serves(string $code, string $path): bool
    {
        return $this->isConfigured()
            ? $this->has($code) && $this->fromPath($path) === $code
            : $code === $this->mainLocale;
    }

    /** The configured display name, built-in language name, or code as a fallback. */
    public function name(string $code): string
    {
        return $this->locales[$code]['name'] ?? self::NAMES[$code] ?? $code;
    }

    /** The address prefix of a language; the main language has an empty prefix. */
    public function prefix(string $code): string
    {
        return $code === $this->mainLocale ? '' : '/' . $code;
    }

    /** The configured HTML language tag, or the content language code. */
    public function htmlLang(string $code): string
    {
        return $this->locales[$code]['html_lang'] ?? $code;
    }

    /** The configured Open Graph locale, or one derived from a language and country tag. */
    public function ogLocale(string $code): ?string
    {
        if (isset($this->locales[$code]['og_locale'])) {
            return $this->locales[$code]['og_locale'];
        }
        if (1 !== \preg_match('~^([a-z]{2,3})-([A-Za-z]{2}|[0-9]{3})\z~', $this->htmlLang($code), $matches)) {
            return null;
        }

        return $matches[1] . '_' . \strtoupper($matches[2]);
    }

    /** The language of the first address segment, falling back to the main language. */
    public function fromPath(string $path): string
    {
        $key = Url::key($path);
        $code = \str_starts_with($key, '/') ? (\explode('/', $key, 3)[1] ?? '') : '';

        return $this->has($code) ? $code : $this->mainLocale;
    }

    /** @internal Validate content input while preserving historical language codes without a list. */
    public function assertUsable(?string $code): string
    {
        if (null === $code || '' === $code) {
            return $this->mainLocale;
        }
        if ($this->isConfigured()) {
            if (!$this->has($code)) {
                throw ContentException::invalid(I18n::t('content.locale_unknown', ['locale' => $code]));
            }
        } elseif (\strlen($code) > 8 || 1 !== \preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})?\z/', $code)) {
            throw ContentException::invalid(I18n::t('http.no_such_language'));
        }

        return $code;
    }

    /** @internal Describe a configured language prefix mismatch, if any. */
    public function addressProblem(string $code, string $url): ?string
    {
        if (!$this->isConfigured() || \count($this->codes()) < 2) {
            return null;
        }
        if ($code === $this->mainLocale) {
            return $this->fromPath($url) === $code ? null : 'content.url_other_locale_prefix';
        }

        $key = Url::key($url);
        $prefix = $this->prefix($code);

        return $key === $prefix || \str_starts_with($key, $prefix . '/') ? null : 'content.url_locale_prefix';
    }
}

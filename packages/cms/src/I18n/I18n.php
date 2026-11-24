<?php

declare(strict_types=1);

namespace Itk\Cms\I18n;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The language of the interface in one call, from anywhere: static methods, exceptions, classes without the container.
 *
 *   I18n::t('users.last_admin')                         a key of translations/itk_users.<locale>.yaml: the domain is «itk_» + the first segment of the key
 *   I18n::t('users.invited', ['name' => $user->name])   {name} in the text; the parameters are passed without the braces
 *   I18n::t('pages.count', ['count' => 5])              `count` also chooses the form when the text has them: «{count} страница|{count} страницы|{count} страниц». In «Найдено: {count}» it is a parameter like any other
 *
 * Under the kernel the bundle hands the facade its translator when it boots (ItkCmsBundle::boot), so the text comes in the current language of LocaleSwitcher (AdminLocale
 * decides which one that is) and a site overrides any domain with its own translations/itk_<area>.<locale>.yaml. Without the kernel (unit tests) the facade reads
 * the YAML of the bundle itself, in the language of useLocale(). An unknown key is not an error: the answer is the key itself, with a warning in the log.
 *
 * i18n-dynamic: i18n.plural_example (the reference of the plural forms of each language; nothing shows it, tests read it)
 */
final class I18n
{
    /** The languages the interface is translated into, each named in itself: what itk_cms.admin_locales may hold. */
    public const LOCALES = ['en' => 'English', 'ru' => 'Русский', 'kk' => 'Қазақша']; // i18n-ignore
    public const FALLBACK = 'en';

    private const DOMAIN_PREFIX = 'itk_';
    private const DIR = __DIR__ . '/../../translations';

    /** @var (\Closure(): AdminLocale)|null gives the translator of the kernel when it is first needed */
    private static ?\Closure $boot = null;
    private static ?TranslatorBagInterface $translator = null;
    private static ?LoggerInterface $logger = null;
    private static string $locale = self::FALLBACK;
    /** @var array<string, array<string, string>> the files of the bundle read so far, by «domain.locale» */
    private static array $files = [];
    /** @var array<string, true> what was reported already: one line in the log per key and language */
    private static array $reported = [];

    /** @param array<string, mixed> $params */
    public static function t(string $key, array $params = []): string
    {
        $domain = self::domain($key);
        $translator = self::translator();
        $translator = $translator instanceof TranslatorInterface ? $translator : null;
        if (null === $translator) {
            $text = self::fromFiles($domain, $key, self::$locale);
        } else {
            $catalogue = $translator->getCatalogue();
            $text = $catalogue->has($key, $domain) ? $catalogue->get($key, $domain) : null;
        }
        if (null === $text) {
            self::report($key);

            return $key;
        }

        $args = [];
        foreach ($params as $name => $value) {
            $args['{' . $name . '}'] = \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        }
        $plain = $args;
        // only a text with forms gets %count%: Symfony reads any other as one form too, takes «Found:» of «Found: {count}» for its label and cuts it off
        if (\array_key_exists('count', $params) && \str_contains($text, '|')) {
            $args['%count%'] = $params['count'];
        }
        try {
            return null === $translator ? (new IdentityTranslator())->trans($text, $args, null, self::$locale) : $translator->trans($key, $args, $domain);
        } catch (\InvalidArgumentException) { // fewer forms than the language needs: the checks catch it in the bundle, nothing checks the files of a site
            self::report($key, 'A text of the admin has too few plural forms');

            return \strtr($text, $plain);
        }
    }

    /** Is the key in the catalogues, in the current language or the fallback one? For texts that have a stored version to fall back on. */
    public static function has(string $key): bool
    {
        $domain = self::domain($key);
        $translator = self::translator();

        return $translator instanceof TranslatorInterface ? $translator->getCatalogue()->has($key, $domain) : null !== self::fromFiles($domain, $key, self::$locale);
    }

    /**
     * The text of the key in every language of the interface: how to recognise what the bundle itself wrote into the database in any of them.
     *
     * @return list<string>
     */
    public static function variants(string $key): array
    {
        $domain = self::domain($key);
        $translator = self::translator();
        $out = [];
        foreach (\array_keys(self::LOCALES) as $locale) {
            $text = $translator instanceof TranslatorInterface && $translator->getCatalogue($locale)->has($key, $domain) ? $translator->getCatalogue($locale)->get($key, $domain) : self::fromFiles($domain, $key, $locale);
            if (null !== $text) {
                $out[] = $text;
            }
        }

        return \array_values(\array_unique($out));
    }

    /** The current language of the interface: the one t() answers in now. */
    public static function locale(): string
    {
        $translator = self::translator();

        return $translator instanceof LocaleAwareInterface ? $translator->getLocale() : self::$locale;
    }

    /** The language of the facade without a kernel (tests/bootstrap.php sets ru). Under the kernel the language is the one of LocaleSwitcher, see AdminLocale. */
    public static function useLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    /**
     * Called when the bundle boots. Nothing is built until the first text is asked for.
     *
     * @param \Closure(): AdminLocale $boot
     */
    public static function boot(\Closure $boot): void
    {
        self::$boot = $boot;
        self::$translator = null;
        self::$logger = null;
    }

    /** Called when the kernel shuts down: the facade goes back to the files of the bundle. */
    public static function shutdown(): void
    {
        self::$boot = null;
        self::$translator = null;
        self::$logger = null;
    }

    private static function translator(): ?TranslatorBagInterface
    {
        if (null !== self::$boot) {
            $admin = (self::$boot)();
            self::$boot = null;
            self::$logger = $admin->logger();
            $translator = $admin->translator();
            if ($translator instanceof TranslatorBagInterface) {
                self::$translator = $translator;
            } else {
                self::$locale = $admin->application(); // a site with framework.translator off: the files of the bundle
            }
        }

        return self::$translator;
    }

    private static function domain(string $key): string
    {
        return self::DOMAIN_PREFIX . \strstr($key . '.', '.', true);
    }

    private static function fromFiles(string $domain, string $key, string $locale): ?string
    {
        return self::file($domain, $locale)[$key] ?? self::file($domain, self::FALLBACK)[$key] ?? null;
    }

    /** @return array<string, string> */
    private static function file(string $domain, string $locale): array
    {
        $name = $domain . '.' . $locale;
        if (!isset(self::$files[$name])) {
            $path = self::DIR . '/' . $name . '.yaml';
            $messages = \is_file($path) ? Yaml::parseFile($path) : [];
            self::$files[$name] = \is_array($messages) ? $messages : [];
        }

        return self::$files[$name];
    }

    private static function report(string $key, string $problem = 'A text of the admin is not in the catalogues'): void
    {
        $locale = self::locale();
        if (!isset(self::$reported[$locale . ':' . $key])) {
            self::$reported[$locale . ':' . $key] = true;
            self::$logger?->warning($problem . ': "{key}" ({locale})', ['key' => $key, 'locale' => $locale]);
        }
    }
}

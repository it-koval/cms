<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\I18n\I18n;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/** The catalogues of the bundle: translations/itk_<area>.<locale>.yaml. The rules every area must keep, and that no key used in the code is missing. */
final class I18nCatalogTest extends TestCase
{
    private const DIR = __DIR__ . '/../../translations';
    /** Forms of a text with {count} in the order of Symfony: how many each language has. */
    private const FORMS = ['en' => 2, 'ru' => 3, 'kk' => 2];

    /** @return array<string, array<string, array<string, string>>> area => locale => key => text */
    private static function catalogs(): array
    {
        $out = [];
        foreach (\glob(self::DIR . '/*') ?: [] as $file) {
            self::assertSame(1, \preg_match('~/itk_([a-z0-9_]+)\.([a-z]+)\.yaml$~', $file, $m), $file . ' is not named itk_<area>.<locale>.yaml');
            $messages = Yaml::parseFile($file);
            self::assertIsArray($messages, $file);
            $out[$m[1]][$m[2]] = $messages;
        }

        return $out;
    }

    #[Test]
    public function everyAreaIsInEveryLanguage(): void
    {
        $catalogs = self::catalogs();
        self::assertNotSame([], $catalogs);
        foreach ($catalogs as $area => $languages) {
            self::assertEqualsCanonicalizing(\array_keys(I18n::LOCALES), \array_keys($languages), $area);
        }
    }

    #[Test]
    public function theKeysAreTheSameInEveryLanguageAndBelongToTheirArea(): void
    {
        foreach (self::catalogs() as $area => $languages) {
            $keys = \array_keys($languages[I18n::FALLBACK]);
            foreach ($keys as $key) {
                self::assertMatchesRegularExpression('/^' . $area . '\.[a-z0-9_]+(\.[a-z0-9_]+)*$/', $key);
            }
            foreach ($languages as $locale => $messages) {
                self::assertEqualsCanonicalizing($keys, \array_keys($messages), $area . ' in ' . $locale);
            }
        }
    }

    #[Test]
    public function noTextIsEmptyAndEveryTextIsOneString(): void
    {
        foreach (self::catalogs() as $languages) {
            foreach ($languages as $locale => $messages) {
                foreach ($messages as $key => $text) {
                    self::assertIsString($text, $key . ' in ' . $locale);
                    self::assertNotSame('', \trim($text), $key . ' in ' . $locale);
                }
            }
        }
    }

    #[Test]
    public function theParametersAreTheSameInEveryLanguage(): void
    {
        foreach (self::catalogs() as $languages) {
            foreach ($languages[I18n::FALLBACK] as $key => $english) {
                $expected = self::parameters($english);
                foreach ($languages as $locale => $messages) {
                    self::assertSame($expected, self::parameters($messages[$key] ?? ''), $key . ' in ' . $locale);
                }
            }
        }
    }

    #[Test]
    public function everyLanguageHasItsNumberOfPluralForms(): void
    {
        foreach (self::catalogs() as $languages) {
            foreach ($languages as $locale => $messages) {
                foreach ($messages as $key => $text) {
                    if (!\str_contains($text, '{count}') || !\str_contains($languages[I18n::FALLBACK][$key], '|')) {
                        continue;
                    }
                    $forms = \explode('|', $text);
                    self::assertCount(self::FORMS[$locale], $forms, $key . ' in ' . $locale);
                    foreach ($forms as $form) {
                        self::assertStringContainsString('{count}', $form, $key . ' in ' . $locale);
                        self::assertDoesNotMatchRegularExpression('/^\w+:/', \trim($form), $key . ' in ' . $locale . ': Symfony takes «word:» at the start of a form for a label and cuts it off');
                    }
                    if ('kk' === $locale) {
                        self::assertSame($forms[0], $forms[1], $key . ': Symfony has no plural rule for kk and always takes the first form, after a number Kazakh uses the singular anyway');
                    }
                }
            }
        }
    }

    #[Test]
    public function everyKeyTheCodeNamesIsInTheCatalogues(): void
    {
        $known = [];
        foreach (self::catalogs() as $languages) {
            $known += $languages[I18n::FALLBACK];
        }
        $missing = [];
        $finder = (new Finder())->files()->in([\dirname(__DIR__, 2) . '/src', \dirname(__DIR__, 2) . '/templates'])->name(['*.php', '*.twig']);
        foreach ($finder as $file) {
            $code = 'php' === $file->getExtension() ? \php_strip_whitespace($file->getPathname()) : (string) \preg_replace('/\{#.*?#\}/s', '', $file->getContents()); // a comment may quote any key
            \preg_match_all('/(?:I18n::t|itk_t|titleKey:|bodyKey:)\(?\s*\'([a-z0-9_]+\.[a-z0-9_.]+)\'/', $code, $found);
            foreach ($found[1] as $key) {
                if (!isset($known[$key])) {
                    $missing[] = $key . ' in ' . $file->getRelativePathname();
                }
            }
        }

        self::assertSame([], $missing);
    }

    /** @return list<string> */
    private static function parameters(string $text): array
    {
        \preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = \array_values(\array_unique($m[1]));
        \sort($names);

        return $names;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * No Russian text in the code of the bundle: a text of the interface is a key of a catalogue (translations/), not a string.
 * Comments may hold any text. Cyrillic that belongs in the code carries the comment `i18n-ignore` on its line.
 * The same rule for the admin is checked by `npm run check:i18n`.
 */
final class I18nCyrillicTest extends TestCase
{
    private const CYRILLIC = '/\p{Cyrillic}/u';

    #[Test]
    public function thereIsNoCyrillicInPhpCodeOutsideComments(): void
    {
        $found = [];
        $files = (new Finder())->files()->in(\dirname(__DIR__, 2) . '/src')->name('*.php');
        foreach ($files as $file) {
            $line = 1;
            $ignored = [];
            foreach (\PhpToken::tokenize($file->getContents()) as $token) {
                $isComment = \in_array($token->id, [\T_COMMENT, \T_DOC_COMMENT], true);
                if ($isComment && \str_contains($token->text, 'i18n-ignore')) {
                    $ignored[$line] = true;
                }
                if (!$isComment && 1 === \preg_match(self::CYRILLIC, $token->text)) {
                    $found[$file->getRelativePathname()][$line] = true;
                }
                $line += \substr_count($token->text, "\n");
            }
            foreach ($ignored as $at => $_) {
                unset($found[$file->getRelativePathname()][$at]);
            }
        }

        self::assertSame([], $this->describe($found));
    }

    #[Test]
    public function thereIsNoCyrillicInTwigTemplatesOutsideComments(): void
    {
        $found = [];
        $files = (new Finder())->files()->in(\dirname(__DIR__, 2) . '/templates')->name('*.twig');
        foreach ($files as $file) {
            $source = $file->getContents();
            $code = (string) \preg_replace_callback('/\{#.*?#\}|<!--.*?-->/s', static fn (array $m): string => \preg_replace('/[^\n]/', ' ', $m[0]) ?? '', $source);
            $raw = \explode("\n", $source);
            foreach (\explode("\n", $code) as $index => $line) {
                if (1 === \preg_match(self::CYRILLIC, $line) && !\str_contains($raw[$index], 'i18n-ignore')) {
                    $found[$file->getRelativePathname()][$index + 1] = true;
                }
            }
        }

        self::assertSame([], $this->describe($found));
    }

    /**
     * @param array<string, array<int, true>> $found
     *
     * @return list<string>
     */
    private function describe(array $found): array
    {
        $out = [];
        foreach ($found as $path => $lines) {
            if ([] !== $lines) {
                $out[] = $path . ': line ' . \implode(', ', \array_keys($lines));
            }
        }

        return $out;
    }
}

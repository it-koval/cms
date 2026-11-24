<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;
use Twig\Lexer;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\Token;

/**
 * Site-owned templates still calling the pre-0.3 Twig functions. Shared by Site Health and itk:doctor.
 * Only lexes: custom site tags/functions need not be registered and no template is compiled or rendered.
 *
 * @internal
 */
final class LegacyTwigFunctionsHealthCheck implements HealthCheckInterface
{
    private const FUNCTIONS = ['menu', 'settings', 'listing', 'media_url', 'media_srcset', 'media_width', 'media_ratio', 'page_url', 'render_sections'];
    private const LIMIT = 10;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return I18n::t('ops.health_legacy_twig');
    }

    public function check(): HealthResult
    {
        $dir = $this->projectDir . '/templates';
        if (!\is_dir($dir)) {
            return HealthResult::ok(I18n::t('ops.health_legacy_twig_ok'));
        }
        $files = [];
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink() && \str_ends_with($file->getFilename(), '.twig')) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException) {
            return HealthResult::warn(I18n::t('ops.health_legacy_twig_unreadable', ['list' => 'templates/']));
        }
        \sort($files, \SORT_STRING);

        // A separate lexer avoids initializing the site's extensions (and their optional dependencies).
        $lexer = new Lexer(new Environment(new ArrayLoader()));
        $names = '/\b(?:' . \implode('|', self::FUNCTIONS) . ')\b/';
        $locations = $unreadable = [];
        $count = $failed = 0;
        foreach ($files as $file) {
            $path = \substr($file, \strlen($this->projectDir) + 1);
            $source = @\file_get_contents($file);
            if (false === $source) {
                ++$failed;
                if (\count($unreadable) < self::LIMIT) {
                    $unreadable[] = $path;
                }

                continue;
            }
            // Comments may separate a name from its parenthesis; only the lexer can recognize a call.
            if (!\preg_match($names, $source)) {
                continue;
            }
            try {
                $stream = $lexer->tokenize(new Source($source, $path));
                $tokens = [];
                while (!$stream->isEOF()) {
                    $tokens[] = $stream->next();
                }
                foreach (self::calls($tokens) as $token) {
                    ++$count;
                    if (\count($locations) < self::LIMIT) {
                        $locations[] = $path . ':' . $token->getLine() . ' (' . $token->getValue() . ')';
                    }
                }
            } catch (\Twig\Error\SyntaxError) {
                // One malformed template cannot hide calls in the remaining files. Do not expose its source/error text.
                ++$failed;
                if (\count($unreadable) < self::LIMIT) {
                    $unreadable[] = $path;
                }
            }
        }
        $messages = [];
        if ($count > 0) {
            $messages[] = I18n::t('ops.health_legacy_twig_found', ['count' => $count, 'list' => self::shortList($locations, $count)]);
        }
        if ($failed > 0) {
            $messages[] = I18n::t('ops.health_legacy_twig_unreadable', ['list' => self::shortList($unreadable, $failed)]);
        }

        return [] === $messages ? HealthResult::ok(I18n::t('ops.health_legacy_twig_ok')) : HealthResult::warn(\implode(' ', $messages));
    }

    /** @param list<string> $values */
    private static function symbol(?Token $token, array $values): bool
    {
        // Twig 3.21 moved (, . and | from punctuation to operators. Test types without values:
        // this works before and after that change and avoids deprecated punctuation-value tests and getType().
        return null !== $token
            && ($token->test(Token::OPERATOR_TYPE) || $token->test(Token::PUNCTUATION_TYPE))
            && \in_array($token->getValue(), $values, true);
    }

    /**
     * Imported macros may have the same short names. Follow their source order and Twig's
     * standard block/macro scopes; an embed starts a separate template with its own imports.
     *
     * @param list<Token> $tokens
     *
     * @return iterable<Token>
     */
    private static function calls(array $tokens): iterable
    {
        $scopes = [[]];
        $embeds = [];
        $tag = null;
        $aliases = [];
        $import = $shortBlock = false;
        foreach ($tokens as $i => $token) {
            if ($token->test(Token::BLOCK_START_TYPE)) {
                $tag = ($tokens[$i + 1] ?? null)?->test(Token::NAME_TYPE) ? $tokens[$i + 1]->getValue() : null;
                if (\in_array($tag, ['block', 'macro'], true)) {
                    $scopes[] = [];
                    $shortBlock = 'block' === $tag && !($tokens[$i + 3] ?? null)?->test(Token::BLOCK_END_TYPE);
                } elseif (\in_array($tag, ['endblock', 'endmacro'], true) && \count($scopes) > 1) {
                    \array_pop($scopes);
                } elseif ('endembed' === $tag && [] !== $embeds) {
                    $scopes = \array_pop($embeds);
                }
            } elseif ($token->test(Token::BLOCK_END_TYPE)) {
                $scopes[\array_key_last($scopes)] += $aliases;
                if ($shortBlock) {
                    \array_pop($scopes);
                }
                if ('embed' === $tag) {
                    $embeds[] = $scopes;
                    $scopes = [[]];
                }
                $tag = null;
                $aliases = [];
                $import = $shortBlock = false;
            } elseif ('from' === $tag && $token->test(Token::NAME_TYPE, 'import')) {
                $import = true;
            } elseif ($import && $token->test(Token::NAME_TYPE) && !$token->test(Token::NAME_TYPE, 'as')) {
                if (!($tokens[$i + 1] ?? null)?->test(Token::NAME_TYPE, 'as')) {
                    $aliases[$token->getValue()] = true;
                }
            }

            if (!$token->test(Token::NAME_TYPE) || !\in_array($token->getValue(), self::FUNCTIONS, true) || !self::symbol($tokens[$i + 1] ?? null, ['('])
                || isset($scopes[\array_key_last($scopes)][$token->getValue()]) || isset($scopes[0][$token->getValue()])) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if (self::symbol($previous, ['.', '?.', '|', 'is', 'is not'])
                || ($previous?->test(Token::NAME_TYPE, ['macro', 'apply', 'filter']) && ($tokens[$i - 2] ?? null)?->test(Token::BLOCK_START_TYPE))
                || (self::symbol($previous, ['not']) && self::symbol($tokens[$i - 2] ?? null, ['is']))) {
                continue;
            }

            yield $token;
        }
    }

    /** @param list<string> $items */
    private static function shortList(array $items, int $total): string
    {
        return \implode(', ', $items) . ($total > self::LIMIT ? I18n::t('ops.doctor_list_more', ['count' => $total - self::LIMIT]) : '');
    }
}

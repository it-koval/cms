<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

/**
 * What a robots.txt text says about one crawler (RFC 9309). Pure: it reads a string and answers; nothing is fetched or stored.
 *
 * Groups: User-agent lines that follow each other open one group, the Allow / Disallow lines after them are its rules; field names and tokens
 * are case-insensitive, `#` starts a comment, a BOM, blank lines, unknown fields (Sitemap, Crawl-delay) and rules before the first User-agent are ignored.
 * Groups that name the same token are merged. A User-agent value is cut to its product token (letters, `_`, `-`) as in Google's parser: `Googlebot/2.1` is Googlebot,
 * `* foo` is `*`; a value with no token at all still counts as a User-agent line for forming the group but names nobody.
 *
 * The group of a crawler: the one that names its token; else for a Yandex token the `Yandex` group (the Yandex bots obey it); else `*`.
 * YandexAdditional and YandexAdditionalBot are the exception chosen here after the Yandex documentation: they do not obey `*`, only a group that
 * names them (or `Yandex`), so with no such group they are allowed. No group at all means allowed.
 *
 * A path is decided by the longest matching rule (the length of its pattern); Allow wins a tie. `*` in a pattern is any run of characters,
 * a closing `$` anchors the end of the path, an empty Disallow allows everything.
 *
 * @internal
 */
final class RobotsRules
{
    /** Tokens that never fall back to the `*` group. */
    private const NO_STAR_FALLBACK = ['yandexadditional', 'yandexadditionalbot'];

    /** @var array<string, list<array{allow: bool, pattern: string}>> lower-case token => rules */
    private array $groups = [];

    public function __construct(string $text)
    {
        $this->parse($text);
    }

    public function isBlocked(string $token, string $path = '/'): bool
    {
        $rules = $this->rulesFor(\strtolower($token));
        $best = null;
        foreach ($rules as $rule) {
            if (!self::matches($rule['pattern'], $path)) {
                continue;
            }
            $length = \strlen($rule['pattern']);
            if (null === $best || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return null !== $best && !$best['allow'];
    }

    /** @return list<array{allow: bool, pattern: string}> */
    private function rulesFor(string $token): array
    {
        if (isset($this->groups[$token])) {
            return $this->groups[$token];
        }
        if (\str_starts_with($token, 'yandex') && isset($this->groups['yandex'])) {
            return $this->groups['yandex'];
        }
        if (\in_array($token, self::NO_STAR_FALLBACK, true)) {
            return [];
        }

        return $this->groups['*'] ?? [];
    }

    private function parse(string $text): void
    {
        $text = \preg_replace('~^\xEF\xBB\xBF~', '', $text) ?? $text;
        $agents = [];
        $collecting = false;
        foreach (\preg_split('~\r\n|\r|\n~', $text) ?: [] as $line) {
            $line = \trim(\explode('#', $line, 2)[0]);
            if (!\str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = \array_map('trim', \explode(':', $line, 2));
            switch (\strtolower($field)) {
                case 'user-agent':
                    if (!$collecting) {
                        $agents = [];
                    }
                    $collecting = true;
                    $token = self::productToken($value);
                    if ('' !== $token) {
                        $agents[] = $token;
                        $this->groups[$token] ??= [];
                    }
                    break;
                case 'allow':
                case 'disallow':
                    $collecting = false;
                    if ('' === $value) {
                        break;
                    }
                    foreach ($agents as $agent) {
                        $this->groups[$agent][] = ['allow' => 'allow' === \strtolower($field), 'pattern' => $value];
                    }
                    break;
            }
        }
    }

    /**
     * The token of a User-agent value as Google's reference parser reads it: `*` when the value starts with `*` and then ends or has white space,
     * else the leading run of letters, `_` and `-` (so `Googlebot/2.1` is `googlebot`). '' when nothing is left: such a line still counts as a
     * User-agent line (it keeps the group going or opens one) but names nobody.
     */
    private static function productToken(string $value): string
    {
        if (1 === \preg_match('~^\*(\s|$)~', $value)) {
            return '*';
        }
        \preg_match('~^[A-Za-z_-]*~', $value, $m);

        return \strtolower($m[0]);
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = \str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = \substr($pattern, 0, -1);
        }
        $regex = '~^' . \str_replace('\*', '.*', \preg_quote($pattern, '~')) . ($anchored ? '$' : '') . '~s';

        return 1 === \preg_match($regex, $path);
    }
}

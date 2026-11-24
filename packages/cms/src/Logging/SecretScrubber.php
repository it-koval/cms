<?php

declare(strict_types=1);

namespace Itk\Cms\Logging;

/** One policy for credentials in transport errors, health output and logs. @internal */
final class SecretScrubber
{
    /** @param list<string> $tokens known secrets that may also occur without a recognizable URL */
    public static function scrub(string $text, #[\SensitiveParameter] array $tokens = [], int $minLength = 1): string
    {
        $forms = [];
        foreach ($tokens as $token) {
            if ('' === $token) {
                continue;
            }
            $json = \json_encode($token);
            $variants = [$token, \base64_encode($token), \htmlspecialchars($token), \rawurlencode($token), \urlencode($token)];
            if (\is_string($json)) {
                $variants[] = \substr($json, 1, -1);
            }
            foreach ($variants as $variant) {
                if (\strlen($variant) >= $minLength) {
                    $forms[$variant] = true;
                }
            }
        }
        $forms = \array_map('strval', \array_keys($forms));
        \usort($forms, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($forms as $form) {
            // Percent escapes are case insensitive; literal token letters remain case sensitive.
            $pattern = \preg_replace_callback('/%[0-9a-f]{2}/i', static fn (array $m): string => '(?i:' . $m[0] . ')', \preg_quote($form, '~'));
            $text = \preg_replace('~' . $pattern . '~', '***', $text) ?? $text;
        }
        $text = \preg_replace('~bot\d+(?::|%3A)[A-Za-z0-9_-]+~i', 'bot***', $text) ?? $text;

        return \preg_replace('~([a-z][a-z0-9+.-]*://)[^\s"\'@/]*@~i', '$1***@', $text) ?? $text;
    }
}

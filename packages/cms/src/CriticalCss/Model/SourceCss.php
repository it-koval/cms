<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * The source CSS handed to crittr, prepared in PHP from the configured stylesheets (pure: the files come in through a reader closure):
 *   - the files concatenated in config order (crittr gets `css` explicitly and never reads document.styleSheets, so an inlined <style> never feeds back);
 *   - a local `@import url(...)` / `@import "..."` replaced by the file it names (one level; crittr drops @import), under `@media` when the import has a media list;
 *   - every relative url(...) rebased to a root path from the stylesheet's own directory (url(../img/a.png) in /css/site.css => url(/img/a.png)),
 *     because the critical CSS is inlined into pages at other paths; data:, #, / and scheme URLs are left alone;
 *   - comments and rules with empty declarations dropped (`a{}`): crittr truncates every rule after one of them.
 *
 * @internal
 */
final class SourceCss
{
    /**
     * @param list<string> $hrefs public paths in cascade order (/css/site.css)
     * @param \Closure(string): ?string $read the content of a public path, null when there is no such file
     *
     * @throws \RuntimeException naming the first missing file
     */
    public static function prepare(array $hrefs, \Closure $read): string
    {
        $out = [];
        foreach ($hrefs as $href) {
            $css = $read($href) ?? throw new \RuntimeException('stylesheet not found: ' . $href);
            $out[] = self::prepareOne($css, $href, $read);
        }

        return \implode("\n", $out);
    }

    /**
     * What crittr loses from this CSS (the fix belongs in crittr, the generator only warns): '!important' and '@layer'.
     *
     * @return list<string>
     */
    public static function lossy(string $css): array
    {
        $found = [];
        if (\str_contains($css, '!important')) {
            $found[] = '!important';
        }
        if (1 === \preg_match('~@layer\b~', $css)) {
            $found[] = '@layer';
        }

        return $found;
    }

    /** @param \Closure(string): ?string $read */
    private static function prepareOne(string $css, string $href, \Closure $read): string
    {
        $dir = self::dirOf($href);
        $css = self::stripComments($css);
        $css = \preg_replace_callback(
            '~@import\s+(?:url\(\s*(["\']?)([^"\')]+)\1\s*\)|(["\'])([^"\']+)\3)\s*([^;]*);~i',
            static function (array $m) use ($dir, $href, $read): string {
                $target = '' !== $m[2] ? $m[2] : $m[4];
                if (self::isExternal($target)) {
                    return $m[0];
                }
                $path = self::resolve($dir, $target);
                $imported = $path === $href ? null : $read($path);
                if (null === $imported) {
                    return '';
                }
                $imported = self::rebaseUrls(self::stripComments($imported), self::dirOf($path));
                $media = \trim($m[5]);

                return '' === $media ? $imported : '@media ' . $media . '{' . $imported . '}';
            },
            $css,
        ) ?? $css;

        return self::dropEmptyRules(self::rebaseUrls($css, $dir));
    }

    public static function rebaseUrls(string $css, string $dir): string
    {
        return \preg_replace_callback(
            '~url\(\s*(["\']?)([^"\')]+)\1\s*\)~i',
            static fn (array $m): string => self::isExternal($m[2]) ? $m[0] : 'url(' . $m[1] . self::resolve($dir, $m[2]) . $m[1] . ')',
            $css,
        ) ?? $css;
    }

    public static function dropEmptyRules(string $css): string
    {
        do {
            $before = $css;
            $css = \preg_replace('~(?<=^|[{};])\s*[^{};]*\{\s*\}~', '', $css) ?? $css;
        } while ($css !== $before);

        return $css;
    }

    private static function stripComments(string $css): string
    {
        return \preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    }

    /** A URL that is not a relative path of the site: absolute, a scheme, protocol-relative, a fragment or data. */
    private static function isExternal(string $url): bool
    {
        $url = \trim($url);

        return '' === $url || \str_starts_with($url, '/') || \str_starts_with($url, '#') || 1 === \preg_match('~^[a-z][a-z0-9+.-]*:~i', $url);
    }

    /** /css/site.css => /css */
    private static function dirOf(string $path): string
    {
        $dir = \dirname($path);

        return '/' === $dir || '.' === $dir ? '' : $dir;
    }

    /** ('/css', '../img/a.png?x=1') => '/img/a.png?x=1' */
    private static function resolve(string $dir, string $relative): string
    {
        $relative = \trim($relative);
        $tail = '';
        if (1 === \preg_match('~^([^?#]*)([?#].*)$~s', $relative, $m)) {
            [, $relative, $tail] = $m;
        }
        $parts = [];
        foreach (\explode('/', $dir . '/' . $relative) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                \array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/' . \implode('/', $parts) . $tail;
    }
}

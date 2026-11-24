<?php

declare(strict_types=1);

namespace Itk\Cms\Support;

/** Addresses of pages. */
final class Url
{
    /** Comparable form: lower case, no trailing slash (except the root). */
    public static function key(?string $url): string
    {
        $s = \mb_strtolower(\trim((string) $url));
        if (\mb_strlen($s) > 1) {
            $s = \rtrim($s, '/');
            if ('' === $s) {
                $s = '/';
            }
        }

        return $s;
    }

    /** Path of a request: leading slash, no trailing slash, always one site path (see safeLocalPath). */
    public static function fromPath(string $path): string
    {
        return '/' . \trim(self::safeLocalPath($path), '/');
    }

    /** Whether the stored address of a page ends with `/` (and is not the root alone): then that spelling is the canonical one. */
    public static function hasTrailingSlash(?string $url): bool
    {
        $s = \trim((string) $url);

        return \strlen($s) > 1 && \str_ends_with($s, '/');
    }

    /**
     * The public spelling of a stored page address: a safe local path (see safeLocalPath) with one trailing slash if the stored value has one, none otherwise; the root is `/`.
     * Comparisons stay on key(); this is only what goes into a link, a `Location` header or the sitemap.
     */
    public static function spelling(?string $url): string
    {
        $path = self::fromPath((string) $url);

        return '/' !== $path && self::hasTrailingSlash($url) ? $path . '/' : $path;
    }

    /** Whether the path has other slashes than the page's own spelling. Letter case stays as served. */
    public static function hasNonCanonicalSlashes(string $path, string $stored): bool
    {
        $own = self::encodePath(self::spelling($stored)); // a saved `//` or `%2F` must not redirect to itself

        return (\str_contains($path, '//') && !\str_contains($own, '//'))
            || (false !== \stripos($path, '%2F') && false === \stripos($own, '%2F'))
            || self::hasTrailingSlash($stored) !== \str_ends_with($path, '/');
    }

    /** Location for a canonical slash redirect, including the original query bytes. */
    public static function canonicalRedirect(string $routePath, string $requestPath, string $stored, string $query): ?string
    {
        if ('' === $routePath || '/' === $routePath || !self::hasNonCanonicalSlashes($requestPath, $stored)) {
            return null;
        }

        return self::encodePath(self::spelling($stored)) . ('' !== $query ? '?' . $query : '');
    }

    /**
     * A path that can go into a `Location` header or an `href` and stays on this site, whatever came in (a request path, a query value, an old link):
     * one leading slash, `\` read as `/` (a browser does the same, so `/\host` would be `//host`), tab / line breaks / other control characters dropped (a browser drops them too).
     * `//host`, `/\host`, `\\host` all become `/host`. Anything else is kept as it is, including a query string and a fragment.
     */
    public static function safeLocalPath(string $path): string
    {
        $s = \preg_replace('~[\x00-\x1f\x7f]+~', '', $path) ?? '';
        $s = \str_replace('\\', '/', \trim($s));

        return '/' . \ltrim($s, '/');
    }

    /**
     * Value of a link field of kind «страница»: a site path (`/blog`, not `//host`, no backslash) or a page id; anything else is '/'.
     * The one rule for page links in fields, menus and the template function page_url().
     */
    public static function cleanPageRef(mixed $value): string
    {
        $s = \is_string($value) ? \trim($value) : '';
        if (self::isLocalPath($s) || 1 === \preg_match('~^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z~', $s)) {
            return $s;
        }

        return '/';
    }

    /** `/path`, `/path?x#y`: starts with one slash (not `//`, not `/\`), no control characters. */
    public static function isLocalPath(string $value): bool
    {
        return 1 === \preg_match('~^/(?![/\\\\])[^\x00-\x1f\x7f]*\z~', $value);
    }

    /**
     * Value of a link field of kind «адрес»: http(s), mailto:, tel:, a site path or an anchor; anything else (javascript:, data:, a relative address) is ''.
     */
    public static function cleanLinkUrl(mixed $value): string
    {
        $s = \is_string($value) ? \trim($value) : '';

        return 1 === \preg_match('~^(https?://|mailto:|tel:|#)~i', $s) || self::isLocalPath($s) ? $s : '';
    }

    /**
     * Last line of defence before a value goes into href="…": the same value, or '' when it carries a scheme other than http, https, mailto, tel
     * (javascript:, data:, vbscript:, also with tabs or spaces hidden inside the scheme, which a browser ignores).
     */
    public static function safeHref(string $href): string
    {
        $probe = \strtolower(\preg_replace('~[\x00-\x20\x7f]+~', '', $href) ?? '');
        if (1 === \preg_match('~^([a-z][a-z0-9+.\-]*):~', $probe, $m) && !\in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
            return '';
        }

        return $href;
    }

    /** A path as it goes into a URL: Latin letters, digits and the usual signs stay, everything else (Cyrillic letters, spaces, quotes, controls) is %-coded. */
    public static function encodePath(string $path): string
    {
        return \preg_replace_callback('~[^A-Za-z0-9\-._\~!$&\'()*+,;=:@/%]~u', static fn (array $m): string => \rawurlencode($m[0]), $path) ?? $path;
    }
}

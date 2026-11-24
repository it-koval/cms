<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

/**
 * The address of a site the way a URL carries it: the scheme, the host in ASCII (an international name in punycode) and the port when it is not the default of the scheme.
 *
 * @internal
 */
final readonly class SiteAddress
{
    private const RESERVED_ZONE = '~(^|\.)(localhost|test|local|example|invalid|internal)$~';

    private function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
    ) {
    }

    /** `https://example.com`, `сайт.рф` is not one (no scheme), `HTTPS://Example.com:443/` is `https://example.com`; null when it is not an address of a site (a path or a query is not part of one). */
    public static function fromUrl(string $url): ?self
    {
        if (1 !== \preg_match('~^(https?)://(\[[0-9a-f:.]+\]|[^/:?#@\s\[\]\\\\\x00-\x1f\x7f]+)(?::([0-9]{1,5}))?/?$~iD', \trim($url), $m)) {
            return null;
        }
        $scheme = \strtolower($m[1]);
        $port = isset($m[3]) ? (int) $m[3] : null;
        $host = \mb_strtolower($m[2]);
        if (null !== $port && ($port < 1 || $port > 65535)) {
            return null;
        }
        if (\str_starts_with($host, '[')) {
            if (false === \filter_var(\trim($host, '[]'), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return null;
            }
        } else {
            $host = \idn_to_ascii($host, \IDNA_USE_STD3_RULES | \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
            if (false === $host || false === \filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)) {
                return null;
            }
        }

        return new self($scheme, $host, $port === ('http' === $scheme ? 80 : 443) ? null : $port);
    }

    /** A site setting may omit the scheme. */
    public static function fromDomain(string $domain): ?self
    {
        $domain = \trim($domain);

        return self::fromUrl(\preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain);
    }

    /** Can a search engine reach this host? Not localhost, an IP address or a reserved zone (.test, .local, .example …). */
    public function isPublic(): bool
    {
        return false === \filter_var(\trim($this->host, '[]'), \FILTER_VALIDATE_IP) && 1 !== \preg_match(self::RESERVED_ZONE, \rtrim($this->host, '.'));
    }

    /** `https://xn--80ak6aa92e.xn--p1ai`, `http://example.com:8080`: no trailing slash. */
    public function origin(): string
    {
        return $this->with($this->host);
    }

    /** origin() with an international name in letters, the way the owner writes it: `https://сайт.рф`. */
    public function unicodeOrigin(): string
    {
        $host = \str_starts_with($this->host, '[') ? false : \idn_to_utf8($this->host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);

        return $this->with(\is_string($host) && '' !== $host ? $host : $this->host);
    }

    private function with(string $host): string
    {
        return $this->scheme . '://' . $host . (null === $this->port ? '' : ':' . $this->port);
    }
}

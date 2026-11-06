<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

/**
 * The address of a site the way a URL carries it: the scheme, the host in ASCII (an international name in punycode) and the port when it is not the default of the scheme.
 * Without ext-intl an international name stays in letters.
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
        if (1 !== \preg_match('~^(https?)://([^/:?#@\s]+)(?::(\d{1,5}))?/?$~i', \trim($url), $m)) { // parse_url() would mangle the bytes of a Cyrillic host on some systems
            return null;
        }
        $scheme = \strtolower($m[1]);
        $port = isset($m[3]) ? (int) $m[3] : null;
        $host = \mb_strtolower($m[2]);
        if (\function_exists('idn_to_ascii')) {
            $host = \idn_to_ascii($host, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
            if (!\is_string($host) || '' === $host) {
                return null;
            }
        }

        return new self($scheme, $host, $port === ('http' === $scheme ? 80 : 443) ? null : $port);
    }

    /** Can a search engine reach this host? Not localhost, an IP address or a reserved zone (.test, .local, .example …). */
    public function isPublic(): bool
    {
        return false === \filter_var($this->host, \FILTER_VALIDATE_IP) && 1 !== \preg_match(self::RESERVED_ZONE, $this->host);
    }

    /** `https://xn--80ak6aa92e.xn--p1ai`, `http://example.com:8080`: no trailing slash. */
    public function origin(): string
    {
        return $this->with($this->host);
    }

    /** origin() with an international name in letters, the way the owner writes it: `https://сайт.рф`. */
    public function unicodeOrigin(): string
    {
        $host = \function_exists('idn_to_utf8') ? \idn_to_utf8($this->host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46) : false;

        return $this->with(\is_string($host) && '' !== $host ? $host : $this->host);
    }

    private function with(string $host): string
    {
        return $this->scheme . '://' . $host . (null === $this->port ? '' : ':' . $this->port);
    }
}

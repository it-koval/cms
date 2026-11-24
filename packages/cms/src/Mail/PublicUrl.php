<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The address of the site for links in letters (a letter is read far from the page that sent it, so a link must be whole).
 * The raw Host header of a request is never trusted: whoever sends a request decides what it holds. In order:
 *   1. the option itk_cms.public_url;
 *   2. the address of the request that is being served, only when the site has framework.trusted_hosts (Symfony has then answered an untrusted Host with 400 already);
 *   3. the setting «domain» (https:// unless it carries a scheme);
 *   4. the address of the request as it came, only in debug mode (development).
 * These fallbacks apply to ordinary site links through base(). Password and invitation links use only the explicit full origin through trustedBase().
 */
final class PublicUrl
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly SettingsService $settings,
        #[Autowire(param: 'itk_cms.public_url')]
        private readonly ?string $configured = null,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug = false,
    ) {
    }

    /** Is this an address of the form «https://example.com» (a trailing slash is allowed, a path and a query are not)? */
    public static function isValid(string $url): bool
    {
        if (\str_contains($url, '\\') || 1 === \preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        // Capture the original authority: parse_url() can replace bytes of an international hostname with underscores.
        if (1 !== \preg_match('~^https?://(?<host>\[[0-9a-f:.]+\]|[^\s/?#@:\[\]]+)(?::(?<port>[0-9]+))?/?$~iuD', $url, $parts)) {
            return false;
        }
        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            return false;
        }
        $host = $parts['host'];
        if (\str_starts_with($host, '[')) {
            return false !== \filter_var(\substr($host, 1, -1), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
        }

        // Keep international domain names as configured; DNS resolution/IDNA is the transport's job.
        $domain = \str_ends_with($host, '.') ? \substr($host, 0, -1) : $host;
        foreach (\explode('.', $domain) as $label) {
            if (1 !== \preg_match('~^[\p{L}\p{N}_](?:[\p{L}\p{M}\p{N}_-]*[\p{L}\p{M}\p{N}_])?$~uD', $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The trusted address of the site without a trailing slash («https://example.com»), or null when there is none.
     *
     * @throws \UnexpectedValueException when itk_cms.public_url (an %env()% placeholder is only known now) holds something that is not an address
     */
    public function base(): ?string
    {
        $base = $this->trustedBase() ?? '';
        if ('' === $base && [] !== Request::getTrustedHosts()) {
            $base = $this->requests->getMainRequest()?->getSchemeAndHttpHost() ?? '';
        }
        if ('' === $base) {
            $domain = \trim((string) $this->settings->get('domain', null, ''));
            if ('' !== $domain) {
                $base = \preg_match('~^https?://~i', $domain) ? $domain : 'https://' . $domain;
            }
        }
        if ('' === $base && $this->debug) {
            $base = $this->requests->getMainRequest()?->getSchemeAndHttpHost() ?? '';
        }

        return '' === $base ? null : \rtrim($base, '/');
    }

    /** Password and invitation links require a configured full origin: trusted_hosts validates only a hostname, not a scheme or port. */
    public function trustedBase(): ?string
    {
        $base = \trim((string) $this->configured);
        if ('' !== $base) {
            if (!self::isValid($base)) {
                throw new \UnexpectedValueException(I18n::t('http.mail_public_url_invalid', ['value' => $base]));
            }

            return \rtrim($base, '/');
        }

        return null;
    }

    /**
     * @param string $path a path of the site starting with «/» (it may carry a query and a #fragment)
     * @param ?string $base an address resolved earlier (a worker has no request); null = base() now. Without any address the path stays relative.
     */
    public function to(string $path, ?string $base = null): string
    {
        return \rtrim($base ?? $this->base() ?? '', '/') . '/' . \ltrim($path, '/');
    }
}

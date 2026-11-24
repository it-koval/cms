<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SiteAddress;
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
        // A configured origin is written as is: whitespace or a control byte anywhere means it is not one.
        return 1 !== \preg_match('/[\x00-\x20\x7f]/', $url) && null !== SiteAddress::fromUrl($url);
    }

    /**
     * The trusted address of the site without a trailing slash («https://example.com»), or null when there is none.
     *
     * @throws \UnexpectedValueException when itk_cms.public_url (an %env()% placeholder is only known now) holds something that is not an address
     */
    public function base(bool $strict = true): ?string
    {
        $configured = $this->configuredAddress($strict);
        if (null !== $configured) {
            return $configured->origin();
        }
        $request = $this->requests->getMainRequest();
        $trusted = [] !== Request::getTrustedHosts() && null !== $request ? SiteAddress::fromUrl($request->getSchemeAndHttpHost()) : null;
        $address = $trusted ?? $this->domainAddress();
        if (null === $address && $this->debug && null !== $request) {
            $address = SiteAddress::fromUrl($request->getSchemeAndHttpHost());
        }

        return $address?->origin();
    }

    /** The address configured by the site, independent of the current request. */
    public function siteAddress(): ?SiteAddress
    {
        return $this->configuredAddress(false) ?? $this->domainAddress();
    }

    /** Optional configured origin, without falling back to a request or the domain setting. */
    public function configuredSiteAddress(): ?SiteAddress
    {
        return $this->configuredAddress(false);
    }

    private function configuredAddress(bool $strict = true): ?SiteAddress
    {
        $base = \trim((string) $this->configured);
        $address = '' === $base ? null : SiteAddress::fromUrl($base);
        if ($strict && '' !== $base && null === $address) {
            throw new \UnexpectedValueException(I18n::t('http.mail_public_url_invalid', ['value' => $base]));
        }

        return $address;
    }

    private function domainAddress(): ?SiteAddress
    {
        return SiteAddress::fromDomain((string) $this->settings->get('domain', null, ''));
    }

    /** The normalized site-configured SEO origin, independent of requests; an invalid optional public_url falls back to domain. */
    public function seoBase(): ?string
    {
        return $this->siteAddress()?->origin();
    }

    /** Password and invitation links require a configured full origin: trusted_hosts validates only a hostname, not a scheme or port. */
    public function trustedBase(): ?string
    {
        return $this->configuredAddress()?->origin();
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

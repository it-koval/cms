<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Decides how the site sends mail and builds the Symfony Mailer transport at the moment of sending, so a change in «Настройки → Почта» works at once, without a restart.
 *   1. «Настройки → Почта» is on and has a server and a sender address → that server;
 *   2. otherwise the environment variable MAILER_DSN, when it holds a real transport (null://null means «ничего не отправлять» and counts as not configured);
 *   3. otherwise mail is «не настроена».
 * The sender (from) is always the one of «Настройки → Почта»; with only MAILER_DSN and no address saved, noreply@<домен сайта> is used when the site has a domain.
 *
 * @internal
 */
final class MailerFactory
{
    public function __construct(
        private readonly MailSettings $settings,
        private readonly SettingsService $site,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire('%env(default::MAILER_DSN)%')]
        #[\SensitiveParameter]
        private readonly ?string $envDsn = null,
    ) {
    }

    /** The way letters go out now, or null when mail is not configured. */
    public function route(): ?MailRoute
    {
        $c = $this->settings->config();
        $from = $this->sender($c);
        if (null === $from) {
            return null;
        }
        if ($c->hasServer()) {
            return $c->hasSafeCredentials() ? new MailRoute(DsnBuilder::build($c), MailRoute::SETTINGS, $from, [$c->password], [$c->username]) : null;
        }
        $env = $this->envDsn();
        if (null !== $env) {
            $secrets = [$env];
            $usernames = [];
            $unsafe = false;
            foreach (self::envTransports($env) as $dsn) {
                if ('' !== (string) $dsn->getPassword()) {
                    $secrets[] = $dsn->getPassword();
                    $unsafe = $unsafe || ('smtp' === $dsn->getScheme() && !$dsn->getBooleanOption('require_tls'));
                }
                if ('' !== (string) $dsn->getUser()) {
                    $usernames[] = $dsn->getUser();
                }
            }

            return new MailRoute($env, MailRoute::ENV, $from, $secrets, $usernames, $unsafe);
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return null !== $this->route();
    }

    /** A transport for the route. No logger on purpose: the SMTP conversation of the debug log holds the login and password in base64. */
    public function transport(MailRoute $route): TransportInterface
    {
        return Transport::fromDsn($route->dsn, $this->dispatcher, null, null);
    }

    /** Is MAILER_DSN set to a real transport? (The settings screen tells the admin that this is the fallback.) */
    public function hasEnvFallback(): bool
    {
        return null !== $this->envDsn();
    }

    /** Diagnostic only: preserve existing MAILER_DSN transports while making optional SMTP encryption visible. */
    public function hasUnsafeEnvCredentials(?MailRoute $route = null): bool
    {
        return ($route ?? $this->route())->unsafeEnvCredentials;
    }

    /** Why mail is not configured, in plain words (null when it is). */
    public function problem(): ?string
    {
        if (null !== $this->route()) {
            return null;
        }
        $c = $this->settings->config();
        if ($c->hasServer() && !$c->hasSafeCredentials()) {
            return I18n::t('http.mail_password_requires_tls');
        }
        if (null === $this->sender($c)) {
            return I18n::t('http.mail_problem_sender');
        }

        return I18n::t('http.mail_problem_server');
    }

    private function sender(MailConfig $c): ?Address
    {
        $address = $c->fromAddress;
        if ('' === $address || false === \filter_var($address, \FILTER_VALIDATE_EMAIL)) {
            $domain = \preg_replace('~^https?://~i', '', \trim((string) $this->site->get('domain', null, '')));
            $domain = \strtolower(\explode('/', (string) $domain)[0]);
            if ('' === $domain || !\preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?\.[a-z]{2,}$/', $domain)) {
                return null;
            }
            $address = 'noreply@' . $domain;
        }
        $name = \str_replace(['"', "\r", "\n"], '', $c->fromName);
        if ('' === $name) {
            $name = \trim((string) $this->site->get('siteName', null, ''));
        }

        try {
            return new Address($address, \str_replace(['"', "\r", "\n"], '', $name));
        } catch (\Throwable) {
            return null;
        }
    }

    private function envDsn(): ?string
    {
        $dsn = \trim((string) $this->envDsn);
        if ('' === $dsn || 1 === \preg_match('~^null(\+[a-z]+)?:~i', $dsn)) {
            return null;
        }

        return $dsn;
    }

    /** @return list<Dsn> Leaf transports in a simple, failover or roundrobin DSN, without constructing a transport or opening a connection. */
    private static function envTransports(#[\SensitiveParameter] string $value): array
    {
        // Symfony's compound DSN grammar separates leaves with a space or a closing parenthesis.
        \preg_match_all('~(?:^|[ (])([a-z][a-z0-9+.-]*://[^ )]+)~i', $value, $matches);
        $transports = [];
        foreach ($matches[1] as $leaf) {
            try {
                $transports[] = Dsn::fromString($leaf);
            } catch (\Throwable) {
                // Normal delivery reports malformed DSNs. Diagnostics must never expose their text.
            }
        }

        return $transports;
    }
}

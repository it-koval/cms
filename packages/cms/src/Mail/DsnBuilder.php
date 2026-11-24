<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Logging\SecretScrubber;

/**
 * The settings of «Почта» as a Symfony Mailer DSN. Login and password are percent-encoded, so «@», «:», «/», «#», «%» and spaces in them cannot change the meaning of the address.
 *   none      smtp://host:25?auto_tls=false              no encryption, no password
 *   ssl       smtps://login:pass@host:465                the connection is encrypted from the first byte (SSL/TLS)
 *   starttls  smtp://login:pass@host:587?require_tls=1   starts plain and upgrades to TLS; no letter goes out if the server cannot.
 */
final class DsnBuilder
{
    public static function build(MailConfig $c): string
    {
        if (!$c->hasSafeCredentials()) {
            throw new \InvalidArgumentException('SMTP authentication with a password requires TLS.');
        }
        $auth = '';
        if ('' !== $c->username) {
            $auth = \rawurlencode($c->username) . ('' !== $c->password ? ':' . \rawurlencode($c->password) : '') . '@';
        }
        $scheme = MailConfig::SSL === $c->encryption ? 'smtps' : 'smtp';
        $query = match ($c->encryption) {
            MailConfig::NONE => '?auto_tls=false',
            MailConfig::STARTTLS => '?require_tls=1',
            default => '',
        };

        return $scheme . '://' . $auth . $c->host . ':' . $c->port . $query;
    }

    /**
     * Cuts the secrets out of a text that is going to the screen or to a log.
     *
     * @param list<string> $secrets
     * @param list<string> $usernames short usernames are omitted from free-text replacement
     */
    public static function clean(string $text, #[\SensitiveParameter] array $secrets, #[\SensitiveParameter] array $usernames = []): string
    {
        return SecretScrubber::scrub(SecretScrubber::scrub($text, $usernames, minLength: 4), $secrets);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

/**
 * The settings of «Почта» as a Symfony Mailer DSN. Login and password are percent-encoded, so «@», «:», «/», «#», «%» and spaces in them cannot change the meaning of the address.
 *   none      smtp://login:pass@host:25?auto_tls=false   no encryption at all
 *   ssl       smtps://login:pass@host:465                the connection is encrypted from the first byte (SSL/TLS)
 *   starttls  smtp://login:pass@host:587?require_tls=1   starts plain and upgrades to TLS; no letter goes out if the server cannot.
 */
final class DsnBuilder
{
    private const MIN_CLEANED = 4;

    public static function build(MailConfig $c): string
    {
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
     */
    public static function clean(string $text, array $secrets): string
    {
        $forms = [];
        foreach ($secrets as $secret) {
            if ('' === $secret) {
                continue;
            }
            foreach ([$secret, \rawurlencode($secret), \urlencode($secret), \base64_encode($secret), \htmlspecialchars($secret)] as $form) {
                if (\strlen($form) >= self::MIN_CLEANED) { // a login or password of one or two signs would garble the whole text, and it would not reveal much anyway
                    $forms[$form] = true;
                }
            }
        }
        // the longest first: a short password must not break a longer form of another
        $forms = \array_keys($forms);
        \usort($forms, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return \str_replace($forms, '***', $text);
    }
}

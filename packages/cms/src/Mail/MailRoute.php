<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Symfony\Component\Mime\Address;

/** How letters go out right now: the DSN (it holds the password: keep it inside the Mail namespace), where it comes from and the sender. */
final class MailRoute
{
    public const SETTINGS = 'settings';
    public const ENV = 'env';

    /**
     * @param list<string> $secrets passwords and DSNs, removed regardless of length
     * @param list<string> $usernames logins, removed when at least four characters long
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $dsn,
        public readonly string $source,
        public readonly Address $from,
        #[\SensitiveParameter]
        public readonly array $secrets,
        #[\SensitiveParameter]
        public readonly array $usernames = [],
        public readonly bool $unsafeEnvCredentials = false,
    ) {
    }
}

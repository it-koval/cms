<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Symfony\Component\Mime\Address;

/** How letters go out right now: the DSN (it holds the password: keep it inside the Mail namespace), where it comes from and the sender. */
final class MailRoute
{
    public const SETTINGS = 'settings';
    public const ENV = 'env';

    /** @param list<string> $secrets everything that must never appear in a text shown to a person */
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $dsn,
        public readonly string $source,
        public readonly Address $from,
        public readonly array $secrets,
    ) {
    }
}

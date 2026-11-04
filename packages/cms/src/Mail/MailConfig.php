<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

/**
 * The «Почта» settings as the code reads them (Mail\MailSettings::config()). The password is here in plain text, for building the connection
 * and for nothing else: it never goes into a response, a log or a message of the queue.
 */
final class MailConfig
{
    public const NONE = 'none';
    public const SSL = 'ssl';
    public const STARTTLS = 'starttls';
    public const ENCRYPTIONS = [self::NONE, self::SSL, self::STARTTLS];

    /** The usual port of each kind of encryption: used when the field «Порт» is left empty. */
    public const DEFAULT_PORTS = [self::NONE => 25, self::SSL => 465, self::STARTTLS => 587];

    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $host = '',
        public readonly int $port = 587,
        public readonly string $encryption = self::STARTTLS,
        public readonly string $username = '',
        #[\SensitiveParameter]
        public readonly string $password = '',
        public readonly string $fromAddress = '',
        public readonly string $fromName = '',
    ) {
    }

    /** Is there enough to open an SMTP connection: switched on, with a server? */
    public function hasServer(): bool
    {
        return $this->enabled && '' !== $this->host && $this->port > 0;
    }
}

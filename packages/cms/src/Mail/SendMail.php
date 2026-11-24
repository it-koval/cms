<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

/**
 * Messenger message (async, default Messenger bus): one letter to one person, already written. The worker connects to the mail server and sends it
 * (SendMailHandler), so a page never waits for the server. It holds the text of the letter and no mail settings: those are read when the letter is sent.
 *
 * kind  what the letter is for: 'reset', 'invite', 'request', 'test'
 * ref   what it is about: the number of a request for 'request'; the failure notification links to it
 */
final class SendMail
{
    public const RESET = 'reset';
    public const INVITE = 'invite';
    public const REQUEST = 'request';
    public const TEST = 'test';

    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly string $kind,
        public readonly ?string $ref = null,
    ) {
    }
}

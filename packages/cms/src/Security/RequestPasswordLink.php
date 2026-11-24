<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

/**
 * Messenger message (async, default Messenger bus): somebody asked for a password link at «Забыли пароль?». The page queues it for EVERY well-formed address and does
 * nothing else, so a known and an unknown address cost the same; the worker finds the person and sends the letter (RequestPasswordLinkHandler).
 *
 * baseUrl  the trusted address of the site, resolved in the request (a worker has none): see Mail\PublicUrl
 */
final class RequestPasswordLink
{
    /** Defaults on deserialization too: queued pre-0.3 jobs did not establish trust. */
    public bool $trusted = false;

    public function __construct(
        public readonly string $email,
        public readonly string $baseUrl,
        bool $trusted = false,
    ) {
        $this->trusted = $trusted;
    }
}

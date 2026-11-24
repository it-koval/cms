<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

/** @internal Durable delivery instructions: no reset token, URL containing a token, or rendered password letter. */
final class DeliverPasswordLink
{
    public function __construct(
        public readonly string $userId,
        public readonly string $email,
        public readonly string $credentialVersion,
        public readonly string $kind,
        public readonly string $ttl,
        public readonly string $baseUrl,
    ) {
    }
}

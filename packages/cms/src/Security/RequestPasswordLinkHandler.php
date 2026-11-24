<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Does the work of «Забыли пароль?» outside the request: a known, not blocked person gets the letter; for anybody else nothing happens.
 *
 * @internal
 */
#[AsMessageHandler]
final class RequestPasswordLinkHandler
{
    public function __construct(private readonly PasswordRecovery $recovery)
    {
    }

    public function __invoke(RequestPasswordLink $message): void
    {
        // Old serialized requests may contain a base resolved from editable settings.
        if (!$message->trusted) {
            return;
        }
        $this->recovery->sendRequested($message->email, $message->baseUrl);
    }
}

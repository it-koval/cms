<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** @internal */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class DeliverPasswordLinkHandler
{
    public function __construct(private readonly PasswordRecovery $recovery)
    {
    }

    public function __invoke(DeliverPasswordLink $message): void
    {
        $this->recovery->deliverLink($message);
    }
}

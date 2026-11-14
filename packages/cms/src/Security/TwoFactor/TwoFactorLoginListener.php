<?php

declare(strict_types=1);

namespace Itk\Cms\Security\TwoFactor;

use Itk\Cms\Mcp\ApiTokenAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Every password sign-in starts unchecked: a code typed earlier in this browser session does not carry over to a new sign-in.
 *
 * @internal
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class TwoFactorLoginListener
{
    public function __construct(private readonly TwoFactorService $factor)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticator() instanceof ApiTokenAuthenticator) {
            return; // a key of an AI assistant has no session
        }
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $this->factor->forget($request->getSession());
        }
    }
}

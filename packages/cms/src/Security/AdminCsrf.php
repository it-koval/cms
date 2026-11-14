<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The CSRF token of the admin API: an HMAC of the signed-in user and the session, so it needs no storage and no csrf component.
 * The page that serves the SPA puts it in window.ITK_ADMIN.csrf; the client sends it in the X-CSRF-Token header of every POST / PUT / PATCH / DELETE.
 *
 * @internal
 */
final class AdminCsrf
{
    public const HEADER = 'X-CSRF-Token';

    public function __construct(
        private readonly Security $security,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
    }

    public function token(Request $request): string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (null !== $session && !$session->isStarted()) {
            $session->start();
        }
        if ('' === $this->secret) {
            throw new \LogicException('APP_SECRET is empty: set a unique value in .env.local of this environment.');
        }
        $who = $this->security->getUser()?->getUserIdentifier() ?? '';
        if (null !== $session && '' === $who && !$session->has('_itk_form')) {
            // a visitor who has not signed in (forgot password, new password): starting the session is not «using» it, and without use the framework sends no session cookie,
            // so the token of the form would never match the one the next request makes
            $session->set('_itk_form', 1);
        }

        return \hash_hmac('sha256', 'itk-admin|' . $who . '|' . ($session?->getId() ?? ''), $this->secret);
    }

    public function isValid(Request $request): bool
    {
        $sent = (string) $request->headers->get(self::HEADER, '');

        return '' !== $sent && \hash_equals($this->token($request), $sent);
    }
}

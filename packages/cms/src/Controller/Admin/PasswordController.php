<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\PasswordChanger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Профиль → Сменить пароль»: the signed-in person changes his own password. Only his own; no special right is needed, the current password is asked.
 *   POST me/password { current, password, repeat? }   { ok }   422 on a wrong current password / a new one shorter than 10 signs, 429 after five wrong tries in 15 minutes
 * The session stays open (its id is not changed: the CSRF token of the open page is made from it).
 *
 * @internal
 */
#[AsController]
#[Route('/api/me/password', name: 'itk_api_me_password_')]
final class PasswordController extends AbstractApiController
{
    public function __construct(
        private readonly Access $access,
        private readonly PasswordChanger $changer,
    ) {
    }

    #[Route('', name: 'change', methods: ['POST'])]
    public function change(Request $request): JsonResponse
    {
        $user = $this->access->user();
        if (!$user instanceof User) {
            throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        }
        $body = $this->body($request);
        $this->changer->change(
            $user,
            \is_string($body['current'] ?? null) ? $body['current'] : '',
            \is_string($body['password'] ?? null) ? $body['password'] : '',
            \is_string($body['repeat'] ?? null) ? $body['repeat'] : null,
        );

        return $this->json(['ok' => true]);
    }
}

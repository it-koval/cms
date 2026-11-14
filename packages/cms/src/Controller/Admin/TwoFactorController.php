<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Профиль → Двухэтапный вход»: the second sign-in factor (TOTP) of the signed-in person. Only his own; no special right is needed.
 *   GET  2fa                  { enabled, pending, required, recoveryLeft, enabledAt }
 *   POST 2fa/setup            { secret, uri }   a new pending secret (the admin draws the QR from `uri`); 409 when it is already on
 *   POST 2fa/enable { code }  { ok, recoveryCodes: [10 strings] }   confirms with the first code; the codes are shown once
 *   POST 2fa/recovery-codes { password }   { recoveryCodes }   new codes instead of the old ones
 *   POST 2fa/disable { password }          { ok }   409 when the role of the person demands the factor
 * A wrong code / password is 422; the fifth in a row locks the factor for 15 minutes (429). The secret is only in the answer of `setup`.
 *
 * @internal
 */
#[AsController]
#[Route('/api/2fa', name: 'itk_api_2fa_')]
final class TwoFactorController extends AbstractApiController
{
    public function __construct(
        private readonly Access $access,
        private readonly TwoFactorService $factor,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json($this->factor->status($this->user()));
    }

    #[Route('/setup', name: 'setup', methods: ['POST'])]
    public function setup(): JsonResponse
    {
        $issuer = \trim((string) $this->settings->get('siteName', null, ''));

        return $this->json($this->factor->startSetup($this->user(), '' !== $issuer ? $issuer : 'ITK CMS'));
    }

    #[Route('/enable', name: 'enable', methods: ['POST'])]
    public function enable(Request $request): JsonResponse
    {
        $user = $this->user();
        $codes = $this->factor->enable($user, (string) ($this->body($request)['code'] ?? ''));
        $this->factor->markVerified($user, $request->getSession()); // he has just proved the app works: this session needs no second code

        return $this->json(['ok' => true, 'recoveryCodes' => $codes]);
    }

    #[Route('/recovery-codes', name: 'recovery', methods: ['POST'])]
    public function recoveryCodes(Request $request): JsonResponse
    {
        return $this->json(['recoveryCodes' => $this->factor->regenerateRecoveryCodes($this->user(), (string) ($this->body($request)['password'] ?? ''))]);
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(Request $request): JsonResponse
    {
        $this->factor->disable($this->user(), (string) ($this->body($request)['password'] ?? ''));

        return $this->json(['ok' => true]);
    }

    private function user(): User
    {
        return $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
    }
}

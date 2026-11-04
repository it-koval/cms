<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\AdminCsrf;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Twig\Environment;

/**
 * /admin/login/otp: the second step of signing in. The password is already checked (the firewall signed the person in); the code of the app or a recovery code
 * opens the rest (see Security\TwoFactor\TwoFactorGate). Wrong codes are counted and lock the factor (TwoFactorService).
 *
 * @internal
 */
#[AsController]
final class TwoFactorLoginController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Access $access,
        private readonly AdminCsrf $csrf,
        private readonly TwoFactorService $factor,
        private readonly SettingsService $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly AdminTheme $theme,
        private readonly ?LogoutUrlGenerator $logoutUrls = null,
    ) {
    }

    #[Route('/login/otp', name: 'itk_admin_login_otp', methods: ['GET', 'POST'])]
    public function otp(Request $request): Response
    {
        $user = $this->access->user();
        if (null === $user) {
            return new RedirectResponse($this->urls->generate('itk_admin_login'));
        }
        if (TwoFactorService::CODE !== $this->factor->gate($user, $request->getSession())) {
            return new RedirectResponse($this->urls->generate('itk_admin_app'));
        }

        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $sent = (string) $request->request->get('_token', '');
            if ('' === $sent || !\hash_equals($this->csrf->token($request), $sent)) {
                $error = I18n::t('http.page_expired_code');
                $status = Response::HTTP_FORBIDDEN;
            } else {
                try {
                    if ($this->factor->verify($user, (string) $request->request->get('code', ''))) {
                        $this->factor->markVerified($user, $request->getSession(), true);

                        return new RedirectResponse($this->urls->generate('itk_admin_app'));
                    }
                    $error = I18n::t('http.otp_wrong');
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } catch (ContentException $e) {
                    $error = $e->getMessage();
                    $status = $e->status();
                }
            }
        }

        $look = $this->theme->shell();
        $html = $this->twig->render('@ItkCms/admin/login_otp.html.twig', [
            'error' => $error,
            'csrf_token' => $this->csrf->token($request),
            'action' => $this->urls->generate('itk_admin_login_otp'),
            'logout_url' => $this->logoutUrl(),
            'email' => $user->email,
            'site_name' => \trim((string) $this->settings->get('siteName', null, '')),
            'theme_css' => $look['css'],
            'theme_brand' => $look['theme']['brand'],
            'theme_logo' => $look['theme']['logo'],
        ]);

        return new Response($html, $status, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    private function logoutUrl(): string
    {
        try {
            return $this->logoutUrls?->getLogoutPath() ?? $this->urls->generate('itk_admin_logout');
        } catch (\Throwable) {
            return $this->urls->generate('itk_admin_logout');
        }
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\AdminCsrf;
use Itk\Cms\Security\PasswordRecovery;
use Itk\Cms\Security\UserManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Public pages of the admin for a person who has no password at hand (the site's security.yaml must let them in without signing in: see the README, «Почта»).
 *   GET|POST /admin/forgot-password      the address → always the same answer «Если такой адрес есть, мы отправили письмо со ссылкой»; when the site cannot send mail the page says to ask the administrator
 *   GET|POST /admin/reset-password/{token}  the link of the letter: a new password (not shorter than 10 signs) and its repeat → the login page with a notice
 * The token is in the address, so these pages send no referrer, are not cached and never show anything about the person until the password is set.
 *
 * @internal
 */
#[AsController]
final class PasswordRecoveryController
{
    public const NOTICES = [
        'password-set' => 'http.notice_password_set',
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly Access $access,
        private readonly AdminCsrf $csrf,
        private readonly PasswordRecovery $recovery,
        private readonly SettingsService $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly AdminTheme $theme,
    ) {
    }

    #[Route('/forgot-password', name: 'itk_admin_forgot_password', methods: ['GET', 'POST'])]
    public function forgot(Request $request): Response
    {
        if (null !== $this->access->user()) {
            return new RedirectResponse($this->urls->generate('itk_admin_app'));
        }
        $sent = false;
        $error = null;
        $status = Response::HTTP_OK;
        $available = $this->recovery->isAvailable();
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = I18n::t('http.page_expired_email');
                $status = Response::HTTP_FORBIDDEN;
            } elseif (!$this->recovery->request((string) $request->request->get('email', ''), $request->getClientIp())) {
                $error = I18n::t('http.too_many_attempts');
                $status = Response::HTTP_TOO_MANY_REQUESTS;
            } else {
                $sent = $available; // the same words for a known and an unknown address
            }
        }

        return $this->page('@ItkCms/admin/forgot_password.html.twig', $status, [
            'available' => $available, 'sent' => $sent, 'error' => $error,
            'csrf_token' => $this->csrf->token($request), 'action' => $this->urls->generate('itk_admin_forgot_password'),
        ]);
    }

    #[Route('/reset-password/{token}', name: 'itk_admin_reset_password', requirements: ['token' => '[A-Za-z0-9_\-]{1,200}'], methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token): Response
    {
        $ip = $request->getClientIp();
        if ($this->recovery->isGuessing($ip)) {
            return $this->page('@ItkCms/admin/reset_password.html.twig', Response::HTTP_TOO_MANY_REQUESTS, ['gone' => true, 'invite' => false, 'error' => null]);
        }
        $link = $this->recovery->find($token, $ip);
        if (null === $link) {
            return $this->page('@ItkCms/admin/reset_password.html.twig', Response::HTTP_GONE, ['gone' => true, 'invite' => false, 'error' => null]);
        }
        $invite = PasswordReset::INVITE === $link->purpose;
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->tokenOk($request)) {
                $error = I18n::t('http.page_expired_password');
                $status = Response::HTTP_FORBIDDEN;
            } else {
                try {
                    $this->recovery->complete($token, (string) $request->request->get('password', ''), (string) $request->request->get('repeat', ''), $ip);

                    return new RedirectResponse($this->urls->generate('itk_admin_login', ['notice' => 'password-set']));
                } catch (ContentException $e) {
                    if (410 === $e->status()) {
                        return $this->page('@ItkCms/admin/reset_password.html.twig', Response::HTTP_GONE, ['gone' => true, 'invite' => false, 'error' => null]);
                    }
                    $error = $e->getMessage();
                    $status = $e->status();
                }
            }
        }

        return $this->page('@ItkCms/admin/reset_password.html.twig', $status, [
            'gone' => false, 'invite' => $invite, 'error' => $error, 'min_length' => UserManager::MIN_PASSWORD_LENGTH,
            'csrf_token' => $this->csrf->token($request), 'action' => $this->urls->generate('itk_admin_reset_password', ['token' => $token]),
        ]);
    }

    private function tokenOk(Request $request): bool
    {
        $sent = (string) $request->request->get('_token', '');

        return '' !== $sent && \hash_equals($this->csrf->token($request), $sent);
    }

    /** @param array<string, mixed> $vars */
    private function page(string $template, int $status, array $vars): Response
    {
        $look = $this->theme->shell();
        $html = $this->twig->render($template, $vars + [
            'site_name' => \trim((string) $this->settings->get('siteName', null, '')),
            'theme_css' => $look['css'], 'theme_brand' => $look['theme']['brand'], 'theme_logo' => $look['theme']['logo'],
            'login_url' => $this->urls->generate('itk_admin_login'), 'forgot_url' => $this->urls->generate('itk_admin_forgot_password'),
            'min_length' => UserManager::MIN_PASSWORD_LENGTH,
        ]);

        return new Response($html, $status, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Security\TwoFactor;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The second step of signing in. The password opens a session, but until the person types a code (/admin/login/otp) nothing of the admin works except the code page,
 * sign-in and logout; a person whose role demands a second factor and who has none yet may only reach the admin shell and /admin/api/2fa (to set it up).
 * The admin and the places inside it are known by their ROUTES (AdminPath), so the gate holds wherever the admin stands.
 * Runs right after the firewall, so it sees who is signed in; it never lets a request through on a doubt, it asks the database every time.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class TwoFactorGate
{
    public function __construct(
        private readonly Security $security,
        private readonly TwoFactorService $factor,
        private readonly UrlGeneratorInterface $urls,
        private readonly AdminPath $admin,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->admin->isAdmin($request) || !$request->hasSession()) {
            return;
        }
        $route = $this->admin->route($request);
        if (\in_array($route, ['itk_admin_logout', 'itk_admin_login', 'itk_admin_login_otp'], true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        $api = $this->admin->isApi($request);
        switch ($this->factor->gate($user, $request->getSession())) {
            case TwoFactorService::CODE:
                if ($api) {
                    throw new ContentException(I18n::t('http.2fa_code_needed'), 401, ['code' => '2fa_code']);
                }
                $event->setResponse(new RedirectResponse($this->urls->generate('itk_admin_login_otp')));

                return;
            case TwoFactorService::ENROL:
                if (\in_array($route, ['itk_admin_app', 'itk_admin_app_slash'], true) || \str_starts_with($route, 'itk_api_2fa_')) {
                    return;
                }
                if ($api) {
                    throw new ContentException(I18n::t('http.2fa_enrol_needed'), 403, ['code' => '2fa_enrol']);
                }
                $event->setResponse(new RedirectResponse($this->urls->generate('itk_admin_app')));

                return;
        }
    }
}

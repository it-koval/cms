<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Admin\AdminPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Browser protections on everything of the admin (wherever it stands, see AdminPath: the app, the sign-in page, the JSON API, the preview): the admin cannot be put in a frame of another site
 * (clickjacking), a browser does not guess the type of an answer, and the address of an admin page does not travel to other sites.
 * The preview frame is on the same address as the admin, so `SAMEORIGIN` / `frame-ancestors 'self'` keep it working. A header a controller already set stays as it is.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class AdminHeadersListener
{
    public function __construct(private readonly AdminPath $admin)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->admin->isAdmin($event->getRequest())) {
            return;
        }
        $headers = $event->getResponse()->headers;
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'same-origin');
        }
    }
}

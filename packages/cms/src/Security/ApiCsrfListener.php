<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every call of the admin API that changes something must carry the X-CSRF-Token header (see AdminCsrf).
 * The API is known by its route (AdminPath), so the check stays on wherever the admin was put. It runs after the router (priority 32), the route is known here.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class ApiCsrfListener
{
    public function __construct(private readonly AdminCsrf $csrf, private readonly AdminPath $admin)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->admin->isApi($request) || $request->isMethodSafe()) {
            return;
        }
        if (!$this->csrf->isValid($request)) {
            throw new ContentException(I18n::t('http.page_expired_retry'), 403, ['code' => 'csrf']);
        }
    }
}

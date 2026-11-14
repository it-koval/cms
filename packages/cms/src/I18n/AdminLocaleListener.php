<?php

declare(strict_types=1);

namespace Itk\Cms\I18n;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Puts the language of AdminLocale on requests and queue messages.
 *
 * Before the firewall (it runs at 8; Symfony's own locale listeners at 16 and 15 are over by then) the admin and MCP get admin_locale, so what the firewall itself says is
 * translated; after it they get the language of the person, ahead of the second-factor gate (6) and the CSRF check (4). An error that stops a request inside the firewall
 * never reaches the second step, so the same choice is made again before ApiErrorListener (10) writes the answer. A refusal of the router (a 405 of a route of the API) comes
 * ahead of the firewall, when the person is not known yet: that answer is in admin_locale.
 * The public form route gets its own language. Pages of the site are not touched: they speak the language the site itself chose, and so does the preview of a page, which must look like it.
 * A request whose language was changed here goes back to admin_locale when it ends (Symfony puts framework.default_locale there), any other stays with what Symfony does. Every message of a
 * worker starts from admin_locale: the services are reset between messages, and nothing a message did stays behind.
 *
 * The body of a StreamedResponse runs after the end of the request, so it no longer speaks the language of the person: put its texts together before the response is returned.
 *
 * @internal
 */
final class AdminLocaleListener implements EventSubscriberInterface
{
    private const FORM_ROUTE = 'itk_request_submit';
    private const MCP_ROUTE = 'itk_mcp';
    private const PREVIEW_ROUTE = 'itk_admin_preview';
    private const APPLIED = '_itk_locale_applied';

    public function __construct(
        private readonly AdminLocale $locale,
        private readonly AdminPath $admin,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['beforeFirewall', 9], ['afterFirewall', 7]],
            KernelEvents::EXCEPTION => ['onException', 20],
            KernelEvents::FINISH_REQUEST => ['onFinish', -16],
            WorkerMessageReceivedEvent::class => 'onMessage',
        ];
    }

    public function beforeFirewall(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ($this->speaksToPeople($request)) {
            $this->use($request, $this->locale->default());
        } elseif (self::FORM_ROUTE === $this->admin->route($request)) {
            $this->use($request, $this->locale->forPublic());
        }
    }

    public function afterFirewall(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->usePersonalLanguage($event->getRequest());
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->usePersonalLanguage($event->getRequest());
        }
    }

    public function onFinish(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest() && $event->getRequest()->attributes->getBoolean(self::APPLIED)) {
            $this->locale->apply($this->locale->default());
        }
    }

    public function onMessage(WorkerMessageReceivedEvent $event): void
    {
        $this->locale->apply($this->locale->default());
    }

    private function usePersonalLanguage(Request $request): void
    {
        if ($this->speaksToPeople($request)) {
            $user = $this->security->getUser();
            $this->use($request, $this->locale->forUser($user instanceof User ? $user : null));
        }
    }

    private function speaksToPeople(Request $request): bool
    {
        $route = $this->admin->route($request);

        return self::PREVIEW_ROUTE !== $route && (self::MCP_ROUTE === $route || $this->admin->isAdmin($request));
    }

    private function use(Request $request, string $locale): void
    {
        $request->attributes->set(self::APPLIED, true);
        $request->setLocale($locale);
        $this->locale->apply($locale);
    }
}

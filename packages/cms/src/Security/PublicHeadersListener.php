<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Browser protections on the public routes of the bundle (the live page, the sitemap, robots.txt, llms.txt, image copies, the form POST, /healthz): a browser does not guess the type of an answer,
 * and a link from a page of the site leaves only the address of the site, not the whole path with its query, for other sites. The option itk_cms.security_headers switches this off.
 * The pages of the site's own controllers are the site's business. A header a controller already set stays as it is.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class PublicHeadersListener
{
    private const ROUTES = ['itk_page', 'itk_sitemap', 'itk_robots', 'itk_llms', 'itk_image_variant', 'itk_image_variant_ratio', 'itk_request_submit', 'itk_healthz'];

    public function __construct(
        #[Autowire(param: 'itk_cms.security_headers')]
        private readonly bool $enabled = true,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest() || !\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }
        $headers = $event->getResponse()->headers;
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }
}

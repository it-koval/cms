<?php

declare(strict_types=1);

namespace Itk\Cms\Analytics;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Counters and verification tags on the public pages of the site, the CMS pages and the pages of the site's own controllers alike.
 * The code that Yandex Webmaster gave the CMS for ownership (WebmasterStore::uin()) goes on the page next to the owner's own. It acts on the HTML (200 or 404) of the main request that is built as one string: not on the admin (including preview), the API, MCP, the profiler and its toolbar, not on a
 * streamed or file response, a compressed body, a redirect or a 304. Routes are told apart by name because the admin path is configurable. It runs after the page cache
 * has stored the page, so a cached page carries the counters too, and the settings are part of the fingerprint of the page, so a changed ETag follows a changed counter.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
final class InjectAnalyticsListener
{
    private const SKIPPED_ROUTES = ['itk_admin', 'itk_api', 'itk_mcp', '_profiler', '_wdt'];

    public function __construct(private readonly SettingsService $settings, private readonly WebmasterStore $webmaster)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !\in_array($response->getStatusCode(), [200, 404], true)) {
            return;
        }
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->headers->has('Content-Encoding')) {
            return;
        }
        if (!\str_starts_with(\strtolower(\trim((string) $response->headers->get('Content-Type'))), 'text/html') || !$this->isPublicRoute($event->getRequest()->attributes->get('_route'))) {
            return;
        }
        $html = $response->getContent();
        if (!\is_string($html) || '' === $html) {
            return;
        }
        $config = AnalyticsConfig::fromSettings($this->settings->all(), $this->webmaster->uin());
        if ($config->isEmpty()) {
            return;
        }
        $injected = AnalyticsInjector::inject($html, $config);
        if ($injected === $html) {
            return;
        }
        $response->setContent($injected);
        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) \strlen($injected));
        }
    }

    private function isPublicRoute(mixed $route): bool
    {
        if (!\is_string($route) || '' === $route) {
            return false;
        }
        foreach (self::SKIPPED_ROUTES as $prefix) {
            if (\str_starts_with($route, $prefix)) {
                return false;
            }
        }

        return true;
    }
}

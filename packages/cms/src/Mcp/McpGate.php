<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Mail\PublicUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Everything /mcp refuses before it looks at the key (runs ahead of the router and the firewall, so a closed door costs the site nothing; the address counts percent-decoded, /m%63p is /mcp):
 *  - MCP is off («Настройки → ИИ-ассистенты»): 404, as if there were no such address;
 *  - any method but POST: 405 (the server offers no event stream and no sessions to close);
 *  - a request from a BROWSER page (it carries `Origin`) of a site that is not ours: 403. A page on another site cannot talk to this endpoint, and a DNS-rebinding
 *    page (its Host is its own, so Host alone proves nothing) is stopped here. Allowed: the address of the site (`itk_cms.public_url`), `itk_cms.mcp.allowed_origins`,
 *    and localhost while the site is in debug mode. Assistants' own programs send no Origin;
 *  - a body above `itk_cms.mcp.max_body_mb`: 413; not JSON: 415.
 * The off switch is also checked by ApiTokenAuthenticator and McpController, so a way round this gate still reaches no tool.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 40)]
final class McpGate
{
    public const PATH = '/mcp';

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        private readonly McpSettings $settings,
        private readonly PublicUrl $publicUrl,
        #[Autowire(param: 'itk_cms.mcp.allowed_origins')]
        private readonly array $allowedOrigins,
        #[Autowire(param: 'itk_cms.mcp.max_body_mb')]
        private readonly int $maxBodyMb,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // the router and the firewall read the path decoded (/m%63p is /mcp), so the gate does too; otherwise the encoded spelling walks around every door below
        if (!$event->isMainRequest() || self::PATH !== \rtrim(\rawurldecode($request->getPathInfo()), '/')) {
            return;
        }
        if (!$this->settings->isEnabled()) {
            $event->setResponse($this->refuse(Response::HTTP_NOT_FOUND, 'not_found', 'Not found'));

            return;
        }
        if (!$request->isMethod('POST')) {
            $response = $this->refuse(Response::HTTP_METHOD_NOT_ALLOWED, 'method_not_allowed', 'This server accepts POST only (no event stream, no sessions)');
            $response->headers->set('Allow', 'POST');
            $event->setResponse($response);

            return;
        }
        $origin = $request->headers->get('Origin');
        if (null !== $origin && !$this->originAllowed($origin)) {
            $event->setResponse($this->refuse(Response::HTTP_FORBIDDEN, 'forbidden_origin', 'Requests from web pages of other sites are not accepted'));

            return;
        }
        if (!\str_starts_with(\strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            $event->setResponse($this->refuse(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'unsupported_media_type', 'Send Content-Type: application/json'));

            return;
        }
        $length = $request->headers->get('Content-Length');
        if (null !== $length && (int) $length > $this->maxBodyMb * 1024 * 1024) {
            $event->setResponse($this->refuse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'too_large', \sprintf('The request is larger than %d MB', $this->maxBodyMb)));
        }
    }

    public function originAllowed(string $origin): bool
    {
        $o = self::originOf($origin);
        if (null === $o) {
            return false;
        }
        $allowed = \array_map(static fn (string $a): ?string => self::originOf($a), $this->allowedOrigins);
        $base = $this->publicUrl->base();
        if (null !== $base) {
            $allowed[] = self::originOf($base);
        }
        if ($this->debug) {
            $host = \parse_url($o, \PHP_URL_HOST);
            if (\in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
                return true;
            }
        }

        return \in_array($o, $allowed, true);
    }

    /** `https://Example.com:443/x` → `https://example.com` (default ports dropped); null when it is not an origin. */
    public static function originOf(string $url): ?string
    {
        $p = \parse_url(\trim($url));
        if (!\is_array($p) || !isset($p['scheme'], $p['host']) || !\in_array(\strtolower($p['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $scheme = \strtolower($p['scheme']);
        $port = $p['port'] ?? null;
        if (('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port)) {
            $port = null;
        }

        return $scheme . '://' . \strtolower($p['host']) . (null !== $port ? ':' . $port : '');
    }

    private function refuse(int $status, string $error, string $message): JsonResponse
    {
        $response = new JsonResponse(['error' => $error, 'error_description' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

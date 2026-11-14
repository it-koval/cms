<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\ApiToken;
use Itk\Cms\Mcp\ApiTokenAuthenticator;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpContextFactory;
use Itk\Cms\Mcp\McpServer;
use Itk\Cms\Mcp\McpSettings;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The MCP endpoint for AI assistants (Streamable HTTP, stateless): POST /mcp with `Authorization: Bearer itk_…` and a JSON-RPC message.
 * Answers `application/json`; a notification gets 202 and no body. GET / DELETE are 405 (McpGate). No cookies, no sessions.
 * What stands in front of it: McpGate (off switch, method, Origin, size), the firewall `itk_mcp` (ApiTokenAuthenticator, which also checks the off switch), and here the off switch once more
 * and the limit of calls per key (a message of a batch counts as one call).
 * A request that arrives without the mark of the firewall (ApiTokenAuthenticator::ATTRIBUTE) means the site did not set the firewall up: that is a 500 `mcp_firewall_not_configured` and a line in the log, not «the key is not valid».
 *
 * @internal
 */
#[AsController]
#[WithMonologChannel('itk_mcp')]
final class McpController
{
    public function __construct(
        private readonly McpServer $server,
        private readonly ApiTokenService $tokens,
        private readonly McpContextFactory $contexts,
        private readonly McpSettings $settings,
        #[Autowire(service: 'limiter.itk_mcp_token')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(param: 'itk_cms.mcp.max_body_mb')]
        private readonly int $maxBodyMb,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/mcp', name: 'itk_mcp', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->settings->isEnabled()) {
            return $this->fail(Response::HTTP_NOT_FOUND, 'not_found', 'Not found');
        }
        if (!$request->attributes->has(ApiTokenAuthenticator::ATTRIBUTE)) {
            // nobody checked the key: the site has no firewall for /mcp (a wrong key is stopped by the firewall itself, before the controller)
            $this->logger->error('MCP is on, but the request reached the controller without the firewall of the keys: add the firewall itk_mcp with Itk\\Cms\\Mcp\\ApiTokenAuthenticator to config/packages/security.yaml (README, «MCP: ИИ-ассистенты»)'); // i18n-ignore

            return $this->fail(Response::HTTP_INTERNAL_SERVER_ERROR, 'mcp_firewall_not_configured', 'The server is not set up for keys: the site administrator has to add the firewall itk_mcp to config/packages/security.yaml (see the README of ITK CMS, «MCP»)');
        }
        try {
            $token = $this->tokens->find((string) $request->attributes->get(ApiTokenAuthenticator::ATTRIBUTE));
        } catch (ContentException) {
            return $this->fail(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'The key is not valid');
        }
        if (!$token->isActive()) {
            return $this->fail(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'The key is not valid');
        }
        $limit = $this->limiter->create($token->id)->consume();
        if (!$limit->isAccepted()) {
            $this->refused($token, 'too_many_calls');

            return $this->tooMany($limit);
        }
        $version = $request->headers->get('MCP-Protocol-Version');
        if (null !== $version && !\in_array($version, McpServer::SUPPORTED_VERSIONS, true)) {
            $this->refused($token, 'unsupported_protocol_version');

            return $this->fail(Response::HTTP_BAD_REQUEST, 'unsupported_protocol_version', 'Supported MCP protocol versions: ' . \implode(', ', McpServer::SUPPORTED_VERSIONS));
        }
        $body = $request->getContent();
        if (\strlen($body) > $this->maxBodyMb * 1024 * 1024) {
            $this->refused($token, 'too_large');

            return $this->fail(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'too_large', \sprintf('The request is larger than %d MB', $this->maxBodyMb));
        }
        // a batch costs one call for each message in it (the first one is paid above)
        $messages = McpServer::messageCount($body);
        if ($messages > 1) {
            try {
                $more = $this->limiter->create($token->id)->consume($messages - 1);
            } catch (\InvalidArgumentException) {
                // more messages than the limit of a whole window: it could never pass
                $this->refused($token, 'too_many_calls');

                return $this->tooMany(null);
            }
            if (!$more->isAccepted()) {
                $this->refused($token, 'too_many_calls');

                return $this->tooMany($more);
            }
        }
        $this->tokens->touch($token);

        $answer = $this->server->handle($body, $this->contexts->forRequest($token));
        if (null === $answer) {
            return new Response('', Response::HTTP_ACCEPTED, ['Cache-Control' => 'no-store']);
        }

        return new Response($answer, Response::HTTP_OK, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    /** A call of a good key that was turned away: a line of the audit log, like the calls that went through (the body is never logged). */
    private function refused(ApiToken $token, string $reason): void
    {
        $this->logger->warning('MCP call refused: {reason}', ['reason' => $reason, 'token' => $token->id, 'user' => $token->user->id]);
    }

    private function tooMany(?RateLimit $limit): JsonResponse
    {
        $response = $this->fail(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_requests', 'Too many calls with this key, slow down');
        $response->headers->set('Retry-After', (string) (null === $limit ? 60 : \max(1, $limit->getRetryAfter()->getTimestamp() - \time())));

        return $response;
    }

    private function fail(int $status, string $error, string $message): JsonResponse
    {
        $response = new JsonResponse(['error' => $error, 'error_description' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

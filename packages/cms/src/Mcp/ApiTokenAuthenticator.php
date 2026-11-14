<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Entity\ApiToken;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The firewall of /mcp: `Authorization: Bearer itk_…`, nothing else. No session, no cookies (so no CSRF to worry about): every request is
 * authenticated on its own and the rest of the core sees the owner of the key as the signed-in user, with his rights.
 * While MCP is off («Настройки → ИИ-ассистенты») nobody is authenticated, whatever the address was spelled like (McpGate answers the same 404 earlier).
 * Every refused key is a line of the audit log (channel itk_mcp): the reason, the address, and the key and its owner when the key is a real one. The key itself never.
 *
 * config/packages/security.yaml:
 *   firewalls:
 *       itk_mcp: { pattern: ^/mcp$, stateless: true, provider: itk_users, user_checker: Itk\Cms\Security\UserChecker, custom_authenticators: [Itk\Cms\Mcp\ApiTokenAuthenticator] }
 *   access_control: - { path: ^/mcp$, roles: ROLE_ITK_USER }   (before the line for ^/admin)
 */
#[WithMonologChannel('itk_mcp')]
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const ATTRIBUTE = '_itk_api_token';

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly McpSettings $settings,
        #[Autowire(service: 'limiter.itk_mcp_failed_auth')]
        private readonly RateLimiterFactoryInterface $failures,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->settings->isEnabled()) {
            throw new CustomUserMessageAuthenticationException('Not found', [], Response::HTTP_NOT_FOUND);
        }
        $plain = self::bearer($request);
        if (null === $plain) {
            $this->refused($request, 'missing');

            throw new CustomUserMessageAuthenticationException('Send the key as "Authorization: Bearer itk_…"');
        }
        try {
            $token = $this->tokens->authenticate($plain);
        } catch (ApiTokenException $e) {
            $retry = $this->failed($request);
            if (null !== $retry) {
                $request->attributes->set('_itk_retry_after', $retry);
                $this->refused($request, 'too_many_wrong_keys', $e->token);

                throw new CustomUserMessageAuthenticationException('Too many wrong keys from this address. Try again later', [], 429, $e);
            }
            $this->refused($request, $e->reason, $e->token);

            throw new CustomUserMessageAuthenticationException($e->getMessage(), [], $e->status, $e);
        }
        $request->attributes->set(self::ATTRIBUTE, $token->id);
        $user = $token->user;

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): \Itk\Cms\Entity\User => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $status = (int) $exception->getCode();
        if (!\in_array($status, [401, 403, 404, 429], true)) {
            $status = 401;
        }
        $response = $this->deny($exception->getMessageKey(), $status);
        if (429 === $status) {
            $response->headers->set('Retry-After', (string) (int) $request->attributes->get('_itk_retry_after', 60));
        }

        return $response;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->deny('Send the key as "Authorization: Bearer itk_…"', 401);
    }

    private function refused(Request $request, string $reason, ?ApiToken $token = null): void
    {
        $this->logger->warning('MCP sign-in refused: {reason}', ['reason' => $reason, 'ip' => $request->getClientIp(), 'token' => $token?->id, 'user' => $token?->user->id]);
    }

    private function deny(string $message, int $status): JsonResponse
    {
        $response = new JsonResponse(['error' => match ($status) {
            401 => 'unauthorized', 404 => 'not_found', 429 => 'too_many_requests', default => 'forbidden'
        }, 'error_description' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store');
        if (401 === $status) {
            $response->headers->set('WWW-Authenticate', 'Bearer realm="itk-mcp"');
        }

        return $response;
    }

    /**
     * A wrong key counts against the address: twenty in 15 minutes, then 429.
     *
     * @return int|null seconds to wait when the address is over the limit
     */
    private function failed(Request $request): ?int
    {
        $limit = $this->failures->create($request->getClientIp() ?? 'unknown')->consume();

        return $limit->isAccepted() ? null : \max(1, $limit->getRetryAfter()->getTimestamp() - \time());
    }

    public static function bearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (1 === \preg_match('/^Bearer[ \t]+(\S+)$/i', \trim($header), $m)) {
            return $m[1];
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpGate;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Мой профиль → Мои токены для ИИ-ассистентов»: the keys of the signed-in person, only his own. Everything is refused while MCP is switched off (403).
 *   GET  me/tokens              { endpoint, scopes: [{ key, label, hint, default, warning?, available }], expires: [days|null …], tokens: [{ id, name, hint, scopes, state, createdAt, lastUsedAt, expiresAt, revokedAt }] }
 *   POST me/tokens { name, scopes: [read…], expires: days|null (default 90; null = no end) }   201 { token: <as in the list>, plain: 'itk_…' } — the key is shown ONCE; 422 for a bad name / scope / term, 409 over 20 active keys, 429 over 10 new keys an hour
 *   POST me/tokens/{id}/revoke  the list entry (404 for a key of somebody else)
 * A key is made only in a session that passed the second sign-in factor (if the person has one or his role demands it). It never has more rights than the person.
 *
 * @internal
 */
#[AsController]
#[Route('/api/me/tokens', name: 'itk_api_me_tokens_')]
final class MyTokensController extends AbstractApiController
{
    /** The term the form offers first. */
    private const DEFAULT_DAYS = 90;

    public function __construct(
        private readonly Access $access,
        private readonly McpSettings $settings,
        private readonly ApiTokenService $tokens,
        private readonly RoleResolver $roles,
        private readonly TwoFactorService $factor,
        private readonly PublicUrl $publicUrl,
        #[Autowire(service: 'limiter.itk_mcp_token_create')]
        private readonly RateLimiterFactoryInterface $createLimit,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->user();
        $offered = McpScopes::offeredTo($this->roles->rightsOf($user));

        return $this->json([
            'endpoint' => ($this->publicUrl->base() ?? $request->getSchemeAndHttpHost()) . McpGate::PATH,
            'scopes' => \array_map(static fn (array $s): array => $s + ['available' => \in_array($s['key'], $offered, true)], McpScopes::catalog()),
            'expires' => [30, 90, 365, null],
            'tokens' => \array_map(fn ($t): array => $this->tokens->present($t), $this->tokens->ofUser($user)),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->user();
        $this->needSecondFactor($user, $request);
        $limit = $this->createLimit->create($user->id)->consume();
        if (!$limit->isAccepted()) {
            throw new ContentException(I18n::t('http.tokens_rate_limit'), 429);
        }
        $body = $this->body($request);
        $scopes = \is_array($body['scopes'] ?? null) ? $body['scopes'] : McpScopes::DEFAULT;
        $unknown = \array_diff(\array_filter($scopes, 'is_string'), McpScopes::ALL);
        if ([] !== $unknown || \count($scopes) !== \count(\array_filter($scopes, 'is_string'))) {
            throw ContentException::invalid(I18n::t('http.token_scope_unknown'), ['scopes' => I18n::t('http.token_scope_choose')]);
        }
        $days = \array_key_exists('expires', $body) ? $body['expires'] : self::DEFAULT_DAYS; // the form sends null for «Без срока»; a body without the term gets the form's default, not a key for ever
        if (null !== $days && (!\is_int($days) || $days < 1)) {
            throw ContentException::invalid(I18n::t('http.token_expiry_invalid'), ['expires' => I18n::t('http.token_expiry_choose')]);
        }
        $made = $this->tokens->create($user, (string) ($body['name'] ?? ''), $scopes, $days);

        return $this->json(['token' => $this->tokens->present($made['token']), 'plain' => $made['plain']], 201);
    }

    #[Route('/{id}/revoke', name: 'revoke', requirements: ['id' => '[^/]+'], methods: ['POST'])]
    public function revoke(string $id): JsonResponse
    {
        $user = $this->user();
        $token = $this->tokens->find($id);
        if ($token->user->id !== $user->id) {
            throw ContentException::notFound(I18n::t('http.token_not_found')); // not even a hint that it exists
        }

        return $this->json($this->tokens->present($this->tokens->revoke($token)));
    }

    private function user(): User
    {
        $user = $this->access->user();
        if (!$user instanceof User) {
            throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        }
        if (!$this->settings->isEnabled()) {
            throw ContentException::forbidden(I18n::t('http.ai_disabled'));
        }

        return $user;
    }

    private function needSecondFactor(User $user, Request $request): void
    {
        $state = $this->factor->gate($user, $request->getSession());
        if (TwoFactorService::OK !== $state) {
            throw new ContentException(I18n::t('http.token_needs_code'), 403, ['code' => TwoFactorService::CODE === $state ? '2fa_code' : '2fa_enrol']);
        }
    }
}

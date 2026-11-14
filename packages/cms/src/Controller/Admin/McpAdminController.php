<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpGate;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Mcp\McpToolListError;
use Itk\Cms\Mcp\McpToolRegistry;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Настройки → ИИ-ассистенты» (area `set:mcp`, administrators by default).
 *   GET  mcp                       { enabled, endpoint, stdio, scopes: [{ key, label, hint, default, warning? }], tools: [{ name, scope, readOnly }], tokens: [every key of every person: { id, name, hint, scopes, state, createdAt, lastUsedAt, expiresAt, revokedAt, owner: { id, name, email, blocked } }] }
 *   PUT  mcp { enabled: bool }     the switch (off by default); the same answer as GET
 *   POST mcp/tokens/{id}/revoke    an administrator stops anybody's key; the same answer as GET
 * The keys themselves are made by each person in «Мой профиль» (MyTokensController).
 *
 * @internal
 */
#[AsController]
#[Route('/api/mcp', name: 'itk_api_mcp_')]
final class McpAdminController extends AbstractApiController
{
    private const AREA = 'set:mcp';

    public function __construct(
        private readonly McpSettings $settings,
        private readonly ApiTokenService $tokens,
        private readonly McpToolRegistry $registry,
        private readonly PublicUrl $publicUrl,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->payload($request));
    }

    #[Route('', name: 'switch', methods: ['PUT'])]
    public function switch(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');
        $body = $this->body($request);
        if (!\array_key_exists('enabled', $body) || !\is_bool($body['enabled'])) {
            throw ContentException::invalid(I18n::t('http.mcp_toggle_needed'));
        }
        $this->settings->setEnabled($body['enabled']);

        return $this->json($this->payload($request));
    }

    #[Route('/tokens/{id}/revoke', name: 'revoke', requirements: ['id' => '[^/]+'], methods: ['POST'])]
    public function revoke(Request $request, string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');
        $this->tokens->revoke($this->tokens->find($id));

        return $this->json($this->payload($request));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $base = $this->publicUrl->base() ?? $request->getSchemeAndHttpHost();
        $tools = [];
        $toolsError = null;
        try {
            $tools = \array_values(\array_map(static fn ($t): array => ['name' => $t->name(), 'scope' => $t->requiredScope(), 'readOnly' => (bool) $t->annotations()['readOnly']], $this->registry->all()));
        } catch (McpToolListError $e) { // a mistake of the site in one tool must not take the panel with it: here it is said and the keys can still be revoked
            $toolsError = $e->getMessage();
        }

        return [
            'enabled' => $this->settings->isEnabled(),
            'endpoint' => $base . McpGate::PATH,
            'stdio' => 'bin/console itk:mcp',
            'scopes' => McpScopes::catalog(),
            'tools' => $tools,
            'toolsError' => $toolsError,
            'tokens' => \array_map(fn ($t): array => $this->tokens->present($t, true), $this->tokens->everyone()),
        ];
    }
}

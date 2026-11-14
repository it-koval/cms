<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Entity\ApiToken;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\Viewer;

/**
 * Who is calling a tool: the key and the person it belongs to. Every check goes through Access, i.e. through the same rights as the admin API
 * (the person is the one in the security token storage; McpServer refuses to work if that is somebody else).
 */
final class McpContext
{
    public function __construct(
        public readonly ApiToken $token,
        private readonly Access $access,
        public readonly string $transport = 'http',
    ) {
    }

    public function user(): User
    {
        return $this->token->user;
    }

    public function viewer(): Viewer
    {
        return $this->access->viewer();
    }

    public function hasScope(string $scope): bool
    {
        return $this->token->hasScope($scope);
    }

    /** The right of the person (the key can only narrow it, never widen). */
    public function can(string $area, string $action = 'view', ?string $template = null): bool
    {
        return $this->access->can($area, $action, $template);
    }

    /** @throws McpToolException with a message the model can pass on to the person */
    public function require(string $area, string $action = 'view', ?string $template = null): void
    {
        if (!$this->can($area, $action, $template)) {
            throw new McpToolException(\sprintf('The person this key belongs to has no right "%s" on "%s"%s. Ask them to change the role or use another key.', $action, $area, null !== $template ? ' (template ' . $template . ')' : ''));
        }
    }

    /** The person the security layer sees right now: must be the owner of the key. */
    public function isSignedInAsOwner(): bool
    {
        return $this->access->user()?->id === $this->token->user->id;
    }

    public function tokenName(): string
    {
        return $this->token->name;
    }
}

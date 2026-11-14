<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Entity\User;
use Itk\Cms\Schema\Schema;

/**
 * Who is looking: the signed-in person with his rights, or the system itself (console commands, imports, queue workers — nobody signed in,
 * everything allowed). The viewer is what field `canSee()`, resource `canSee()`, actions and hooks ask.
 *
 *   $v->can('col:tours', 'publish')        $v->allows('set:users')        $v->allows('col:tours:edit')
 */
final class Viewer
{
    /**
     * @param array{perms: array<string, list<string>>, templates: list<string>|null} $rights
     */
    private function __construct(
        private readonly ?User $user,
        private readonly array $rights,
    ) {
    }

    /**
     * @param array{perms: array<string, list<string>>, templates: list<string>|null} $rights
     */
    public static function of(User $user, array $rights): self
    {
        return new self($user, User::BLOCKED === $user->status ? ['perms' => [], 'templates' => null] : $rights);
    }

    /** No person: code is running on its own. Everything is allowed. */
    public static function system(): self
    {
        return new self(null, ['perms' => [], 'templates' => null]);
    }

    public function isSystem(): bool
    {
        return null === $this->user;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function id(): ?string
    {
        return $this->user?->id;
    }

    public function email(): ?string
    {
        return $this->user?->email;
    }

    public function name(): string
    {
        return $this->user->name ?? '';
    }

    /** Key of the person's role ('admin', 'editor' …), null for the system. */
    public function role(): ?string
    {
        return $this->user?->role;
    }

    public function can(string $area, string $action = 'view', ?string $template = null): bool
    {
        return null === $this->user || RoleResolver::allows($this->rights, $area, $action, $template);
    }

    /**
     * A right written as a string: an area ('set:users', 'col:tours' = «смотреть») or 'area:action' ('col:tours:publish', 'pages:edit').
     * The last colon splits the action off only when what follows is one of view | edit | publish | delete.
     */
    public function allows(string $spec): bool
    {
        $cut = \strrpos($spec, ':');
        if (false !== $cut && \in_array(\substr($spec, $cut + 1), Schema::ACTIONS, true)) {
            return $this->can(\substr($spec, 0, $cut), \substr($spec, $cut + 1));
        }

        return $this->can($spec);
    }
}

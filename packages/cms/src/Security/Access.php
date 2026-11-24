<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Shortcut for controllers of the admin API: Access::require('pages', 'publish', $template). */
final class Access
{
    public function __construct(
        private readonly Security $security,
        private readonly RoleResolver $resolver,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    public function user(): ?User
    {
        $u = $this->security->getUser();

        return $u instanceof User ? $u : null;
    }

    /** The signed-in person with his rights, an anonymous HTTP viewer, or the trusted system when no request is running. */
    public function viewer(): Viewer
    {
        $u = $this->user();

        if (null === $u) {
            return null === $this->requests?->getMainRequest() ? Viewer::system() : Viewer::anonymous();
        }

        return Viewer::of($u, $this->resolver->rightsOf($u));
    }

    public function can(string $area, string $action = 'view', ?string $template = null): bool
    {
        $u = $this->user();

        return null !== $u && User::BLOCKED !== $u->status && RoleResolver::allows($this->resolver->rightsOf($u), $area, $action, $template);
    }

    /** @throws AccessDeniedException */
    public function require(string $area, string $action = 'view', ?string $template = null): void
    {
        if (!$this->can($area, $action, $template)) {
            throw new AccessDeniedException(I18n::t('http.no_permission'));
        }
    }

    /**
     * The signed-in user with his effective rights, for GET /admin/api/me.
     *
     * @return array<string, mixed>|null
     */
    public function me(): ?array
    {
        $u = $this->user();
        if (null === $u) {
            return null;
        }
        $rights = $this->resolver->rightsOf($u);

        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status, 'roleId' => $u->role,
            'perms' => $rights['perms'], 'templates' => $rights['templates'],
        ];
    }
}

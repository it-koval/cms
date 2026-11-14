<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Support\Json;

/**
 * The signed-in person as the admin reads him (GET /admin/api/me and window.ITK_ADMIN.user).
 *
 * @internal
 */
final class CurrentUser
{
    public function __construct(
        private readonly Access $access,
        private readonly RoleRepository $roles,
        private readonly TwoFactorService $factor,
        private readonly AdminLocale $locale,
    ) {
    }

    /**
     * @return array<string, mixed>|null { id, name, email, status, roleId, roleName, preset, builtin, perms, templates, twoFactor: { enabled, required, enrol }, locale, localePreference }
     *                                   (`enrol`: the role demands a second factor and he has none: the admin shows only the setup; `locale`: the language of the interface he gets,
     *                                   `localePreference`: the one he chose, null = the language of the site)
     */
    public function present(): ?array
    {
        $me = $this->access->me();
        if (null === $me) {
            return null;
        }
        $role = $this->roles->find($me['roleId']);
        $user = $this->access->user();
        $enabled = null !== $user && $this->factor->isEnabled($user);
        $required = null !== $role && $role->require2fa;

        return [
            'id' => $me['id'], 'name' => $me['name'], 'email' => $me['email'], 'status' => $me['status'], 'roleId' => $me['roleId'],
            'roleName' => null !== $role ? RoleLabel::name($role) : I18n::t('http.no_role'), 'preset' => null !== $role && $role->builtin ? $role->key : null, 'builtin' => null !== $role && $role->builtin,
            'perms' => Json::obj($me['perms']), 'templates' => $me['templates'],
            'twoFactor' => ['enabled' => $enabled, 'required' => $required, 'enrol' => $required && !$enabled],
            'locale' => $this->locale->forUser($user), 'localePreference' => $user?->locale,
        ];
    }
}

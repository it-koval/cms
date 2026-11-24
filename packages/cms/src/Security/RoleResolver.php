<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Schema\Schema;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Works out the rights of a role against the permission areas of the schema (mirror of admin/src/utils/permissions.js).
 * Built-in roles follow the structure of the site; custom roles carry perms { area: [actions] } and templates (null = every template).
 *
 * @internal
 */
final class RoleResolver implements ResetInterface
{
    private const ORDER = ['view', 'edit', 'publish', 'delete'];

    /** @var array<string, array{perms: array<string, list<string>>, templates: list<string>|null}> */
    private array $cache = [];

    public function __construct(
        private readonly Schema $schema,
        private readonly RoleRepository $roles,
    ) {
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Add what a set of actions needs (Публиковать / Удалять -> Править -> Смотреть), in the order of the matrix columns.
     *
     * @param list<string> $list
     *
     * @return list<string>
     */
    public static function closeActions(array $list): array
    {
        $s = \array_flip($list);
        if (isset($s['publish']) || isset($s['delete'])) {
            $s['edit'] = true;
        }
        if (isset($s['edit'])) {
            $s['view'] = true;
        }

        return \array_values(\array_filter(self::ORDER, static fn (string $a): bool => isset($s[$a])));
    }

    /**
     * @return array{perms: array<string, list<string>>, templates: list<string>|null}
     */
    public function resolve(Role $role): array
    {
        $ck = $role->key . '|' . \md5((string) \json_encode([$role->perms, $role->templates, $role->builtin]));
        if (isset($this->cache[$ck])) {
            return $this->cache[$ck];
        }
        $perms = [];
        $templates = null;
        $areas = $this->schema->permissionAreas();
        if ($role->builtin) {
            foreach ($areas as $a) {
                $acts = self::closeActions($this->preset($role->key, $a));
                $acts = \array_values(\array_intersect($acts, $a['actions']));
                if ([] !== $acts) {
                    $perms[$a['key']] = $acts;
                }
            }
            if (Role::AUTHOR === $role->key) {
                $templates = $this->schema->articleTemplates();
            }
        } else {
            foreach ($areas as $a) {
                $have = \array_values(\array_intersect((array) ($role->perms[$a['key']] ?? []), $a['actions']));
                $acts = \array_values(\array_intersect(self::closeActions($have), $a['actions']));
                if ([] !== $acts) {
                    $perms[$a['key']] = $acts;
                }
            }
            $templates = \is_array($role->templates)
                ? \array_values(\array_filter($role->templates, fn (string $k): bool => null !== $this->schema->template($k)))
                : null;
        }

        return $this->cache[$ck] = ['perms' => $perms, 'templates' => $templates];
    }

    /** @return array{perms: array<string, list<string>>, templates: list<string>|null} */
    public function rightsOf(User $user): array
    {
        $role = $this->roles->find($user->role);

        return null === $role ? ['perms' => [], 'templates' => null] : $this->resolve($role);
    }

    /**
     * @param array{perms: array<string, list<string>>, templates: list<string>|null} $rights
     */
    public static function allows(array $rights, string $area, string $action = 'view', ?string $template = null): bool
    {
        if (!\in_array($action, $rights['perms'][$area] ?? [], true)) {
            return false;
        }
        if ('pages' === $area && null !== $template && \is_array($rights['templates']) && !\in_array($template, $rights['templates'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Does `holder` have every right of `role`, incl. the template scope of «Страницы»? Nobody assigns a role bigger than his own.
     *
     * @param array{perms: array<string, list<string>>, templates: list<string>|null} $holder
     * @param array{perms: array<string, list<string>>, templates: list<string>|null} $role
     */
    public static function covers(array $holder, array $role): bool
    {
        foreach ($role['perms'] as $area => $actions) {
            foreach ($actions as $a) {
                if (!\in_array($a, $holder['perms'][$area] ?? [], true)) {
                    return false;
                }
            }
        }
        if (!isset($role['perms']['pages']) || null === $holder['templates']) {
            return true;
        }

        return \is_array($role['templates']) && [] === \array_diff($role['templates'], $holder['templates']);
    }

    /**
     * @param array<string, mixed> $area
     *
     * @return list<string>
     */
    private function preset(string $key, array $area): array
    {
        return match ($key) {
            Role::ADMIN => $area['actions'],
            Role::EDITOR => 'content' === $area['group'] || 'media' === $area['key'] ? $area['actions'] : ('requests' === $area['key'] ? ['view'] : []),
            Role::AUTHOR => 'pages' === $area['key'] || 'media' === $area['key'] ? ['view', 'edit'] : [],
            Role::REQUESTS => 'requests' === $area['key'] ? ['view', 'edit'] : [],
            default => [],
        };
    }
}

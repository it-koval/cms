<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Entity\Role;
use Itk\Cms\I18n\I18n;

/**
 * How a role is called on the screen. A built-in role that nobody renamed speaks the language of the reader (its stored name is one of the catalogues, in any language: that is what
 * RoleSeeder wrote); a built-in role under another name and every role people made show what is stored.
 *
 * @internal
 */
final class RoleLabel
{
    public const BUILTIN = [Role::ADMIN, Role::EDITOR, Role::AUTHOR, Role::REQUESTS];

    public static function name(Role $role): string
    {
        return self::fromCatalogue($role) ? I18n::t(self::nameKey($role->key)) : $role->name;
    }

    public static function description(Role $role): string
    {
        return self::fromCatalogue($role) ? I18n::t(self::descriptionKey($role->key)) : $role->description;
    }

    public static function nameKey(string $key): string
    {
        return 'i18n.role_' . $key . '_name';
    }

    public static function descriptionKey(string $key): string
    {
        return 'i18n.role_' . $key . '_description';
    }

    private static function fromCatalogue(Role $role): bool
    {
        return $role->builtin && \in_array($role->key, self::BUILTIN, true) && \in_array($role->name, I18n::variants(self::nameKey($role->key)), true);
    }
}

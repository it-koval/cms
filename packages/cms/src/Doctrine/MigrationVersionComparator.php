<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * Order of migrations: by the date in the class name (Version20261030120000 = 2026-10-30 12:00:00), the namespace only breaks a tie.
 * Doctrine's own order is by the whole class name, so every migration of the site (DoctrineMigrations\…) would run before every migration of the bundle
 * (Itk\Cms\Migrations\…) and a site migration that reads a bundle table would fail on an empty database. The bundle registers this class for every site
 * (ItkCmsBundle::prependExtension); a site that sets its own `doctrine_migrations.services` comparator keeps it.
 *
 * @internal
 */
final class MigrationVersionComparator implements Comparator
{
    public function compare(Version $a, Version $b): int
    {
        return [self::stamp((string) $a), (string) $a] <=> [self::stamp((string) $b), (string) $b];
    }

    /** The 14 digits after "Version" in the class name; a class named differently goes last. */
    public static function stamp(string $class): string
    {
        $short = \substr($class, (int) \strrpos('\\' . $class, '\\'));

        return 1 === \preg_match('/^Version(\d{14})/', $short, $m) ? $m[1] : '99999999999999';
    }
}

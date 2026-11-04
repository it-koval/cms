<?php

declare(strict_types=1);

namespace Itk\Cms\Admin;

/**
 * The built admin in the package: public/admin, made by `npm run build` (admin/vite.config.js).
 * Its Vite manifest gives the shell the entry files (Controller\Admin\AdminAppController) and the sign-in pages the font (Theme\AdminFont).
 *
 * @internal
 */
final class AdminBuild
{
    public const DIR = __DIR__ . '/../../public/admin';

    /**
     * The manifests of a build that can be read: the one of the current Vite first, then the one of an older Vite.
     *
     * @return list<array<mixed>>
     */
    public static function manifests(string $dir = self::DIR): array
    {
        $manifests = [];
        foreach (['/.vite/manifest.json', '/manifest.json'] as $name) {
            $manifest = \is_file($dir . $name) ? \json_decode((string) \file_get_contents($dir . $name), true) : null;
            if (\is_array($manifest)) {
                $manifests[] = $manifest;
            }
        }

        return $manifests;
    }
}

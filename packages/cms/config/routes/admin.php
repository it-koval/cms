<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Admin routes under itk_cms.admin_path (/admin by default): the SPA (/admin), login (/admin/login), preview (/admin/preview/{id}), JSON API (/admin/api/*).
 * Every controller under src/Controller/Admin/ declares its path WITHOUT that prefix. The router puts the option in (%itk_cms.admin_path_segments% is the same path without
 * the first slash). The import may carry a prefix of its own (`prefix: /cp` → /cp/admin/…): the protection of the admin follows the routes, not the address.
 *
 * config/routes/itk.yaml of the site:
 *   itk_admin:
 *     resource: '@ItkCmsBundle/config/routes/admin.php'
 */
return static function (RoutingConfigurator $routes): void {
    if (\is_dir(__DIR__ . '/../../src/Controller/Admin')) {
        $routes->import('../../src/Controller/Admin/', 'attribute')->prefix('/%itk_cms.admin_path_segments%', false);
    }
};

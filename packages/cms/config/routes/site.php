<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Public routes: POST /request/{form} and the catch-all page route (priority -1000, so every route of the site wins over it).
 *
 * config/routes/itk.yaml of the site:
 *   itk_site:
 *     resource: '@ItkCmsBundle/config/routes/site.php'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../src/Controller/Site/', 'attribute');
};

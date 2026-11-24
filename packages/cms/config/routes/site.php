<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Site routes:
 * - POST /request/{form} and /mcp
 * - GET/HEAD /healthz, /sitemap.xml, /robots.txt and /llms.txt
 * - GET/HEAD /{key}.txt for the IndexNow key
 * - GET/HEAD %itk_cms.upload_url%/_v/w{width}/{path} and %itk_cms.upload_url%/_v/w{width}-r{a}x{b}/{path} for image variants
 * - GET/HEAD /{path} for pages (catch-all with priority -1000)
 *
 * config/routes/itk.yaml of the site:
 *   itk_site:
 *     resource: '@ItkCmsBundle/config/routes/site.php'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../src/Controller/Site/', 'attribute');
};

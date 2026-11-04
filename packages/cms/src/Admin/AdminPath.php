<?php

declare(strict_types=1);

namespace Itk\Cms\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the admin stands, and which requests are the admin's: one source for the listeners (CSRF, headers, the second factor, JSON errors), for the shell that hands
 * addresses to the client and for the letters.
 *
 * The place is the option itk_cms.admin_path (/admin by default) under the prefix of the site's import of config/routes/admin.php (`prefix: /cp` → /cp/admin);
 * base() asks the router, so both count. A request is the admin's by its ROUTE (the names itk_admin_* are pages, itk_api_* the JSON API), never by the string of its
 * path: the string is only a second net for what has no route (a 404 inside the admin) and for a route that a site put under the admin address under another name.
 * The two nets work together (either one is enough), so a protection never switches off because the admin was moved.
 */
final class AdminPath
{
    private const PAGES = 'itk_admin_';
    private const API = 'itk_api_';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(param: 'itk_cms.admin_path')]
        private readonly string $configured,
    ) {
    }

    /** The address of the admin shell without a trailing slash: «/admin», «/cp/admin». */
    public function base(): string
    {
        try {
            return \rtrim($this->urls->generate('itk_admin_app'), '/');
        } catch (RouteNotFoundException) {
            return $this->configured; // the routes are not imported: the option is all there is
        }
    }

    /** An address inside the admin: to('/api/me') → «/admin/api/me»; to('/preview/5'), to('/reset-password/…'). */
    public function to(string $path = ''): string
    {
        return $this->base() . $path;
    }

    /** The name of the route that serves the request ('' before the router has run or when nothing matched). */
    public function route(Request $request): string
    {
        return (string) $request->attributes->get('_route', '');
    }

    /** A page of the admin (the shell, sign-in, password pages, preview) or its API. */
    public function isAdmin(Request $request): bool
    {
        $route = $this->route($request);
        if (\str_starts_with($route, self::PAGES) || \str_starts_with($route, self::API)) {
            return true;
        }
        $path = $this->pathOf($request);
        $base = $this->base();

        return $path === $base || \str_starts_with($path, $base . '/');
    }

    /** A call of the JSON API of the admin. */
    public function isApi(Request $request): bool
    {
        if (\str_starts_with($this->route($request), self::API)) {
            return true;
        }

        return \str_starts_with($this->pathOf($request), $this->base() . '/api/');
    }

    /** The path as the router reads it (percent-encoding undone), with the script prefix the generator also puts in. */
    private function pathOf(Request $request): string
    {
        return $request->getBaseUrl() . \rawurldecode($request->getPathInfo());
    }
}

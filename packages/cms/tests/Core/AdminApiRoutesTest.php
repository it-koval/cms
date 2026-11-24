<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

final class AdminApiRoutesTest extends WebTestCase
{
    private const PUBLIC_ADMIN_ROUTES = [
        'itk_admin_login',
        'itk_admin_login_otp',
        'itk_admin_forgot_password',
        'itk_admin_reset_password',
    ];

    private const PARAMETERS = [
        'action' => 'publish',
        'col' => 'faq',
        'id' => '1',
        'key' => 'test-route',
        'kind' => 'page',
        'locale' => 'en',
        'resource' => 'pages',
        'token' => '0123456789abcdef0123456789abcdef01234567',
    ];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
            adminPath: $options['adminPath'] ?? null,
            routePrefix: $options['routePrefix'] ?? '',
        );
    }

    /** @return iterable<string, array{?string, string}> */
    public static function adminMounts(): iterable
    {
        yield 'default' => [null, ''];
        yield 'custom admin path under an import prefix' => ['/panel', '/cp'];
    }

    #[Test]
    #[DataProvider('adminMounts')]
    public function everyAdminApiRouteRequiresSignInForAllItsMethods(?string $adminPath, string $routePrefix): void
    {
        $client = self::createClient(['adminPath' => $adminPath, 'routePrefix' => $routePrefix]);
        $client->disableReboot();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $apiPath = $router->generate('itk_admin_app') . '/api';
        $checked = 0;
        $publicRoutes = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $isApi = $apiPath === $route->getPath() || \str_starts_with($route->getPath(), $apiPath . '/') || \str_starts_with($name, 'itk_api_');
            if (\in_array($name, self::PUBLIC_ADMIN_ROUTES, true)) {
                self::assertFalse($isApi, $name . ' belongs outside the admin API');
                $publicRoutes[] = $name;

                continue;
            }
            if (!$isApi) {
                continue;
            }

            $parameters = \array_intersect_key(self::PARAMETERS, \array_flip($route->compile()->getVariables()));
            $url = $router->generate($name, $parameters);
            $methods = $route->getMethods();
            self::assertNotEmpty($methods, $name . ' must declare its HTTP methods');
            if (\in_array(Request::METHOD_GET, $methods, true) && !\in_array(Request::METHOD_HEAD, $methods, true)) {
                $methods[] = Request::METHOD_HEAD;
            }
            foreach ($methods as $method) {
                $client->request($method, $url, server: ['HTTP_ACCEPT' => 'application/json']);
                $request = $client->getRequest();
                self::assertInstanceOf(Request::class, $request);
                self::assertSame($name, $request->attributes->get('_route'), "$method $url must match $name");
                self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode(), "$method $url ($name)");
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
        self::assertEqualsCanonicalizing(self::PUBLIC_ADMIN_ROUTES, $publicRoutes);
    }
}

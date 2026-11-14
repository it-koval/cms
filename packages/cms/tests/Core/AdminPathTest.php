<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Request;

/** Место админки: опция itk_cms.admin_path, проверка её формата и признак «это админка / её API» по маршруту. */
final class AdminPathTest extends KernelTestCase
{
    private function path(): AdminPath
    {
        self::bootKernel();

        return self::getContainer()->get(AdminPath::class);
    }

    /** @param array<string, string> $attributes */
    private function request(string $uri, array $attributes = []): Request
    {
        $request = Request::create($uri);
        $request->attributes->add($attributes);

        return $request;
    }

    #[Test]
    public function byDefaultTheAdminIsAtSlashAdmin(): void
    {
        $path = $this->path();

        self::assertSame('/admin', $path->base());
        self::assertSame('/admin/api/me', $path->to('/api/me'));
        self::assertSame('/admin', $path->to());
        self::assertSame('/admin', self::getContainer()->getParameter('itk_cms.admin_path'));
    }

    #[Test]
    public function theRouteDecidesNotTheString(): void
    {
        $path = $this->path();

        // the routes of the admin, under whatever address they were imported to
        self::assertTrue($path->isAdmin($this->request('/somewhere/else', ['_route' => 'itk_admin_login'])));
        self::assertFalse($path->isApi($this->request('/somewhere/else', ['_route' => 'itk_admin_login'])));
        self::assertTrue($path->isAdmin($this->request('/somewhere/else', ['_route' => 'itk_api_pages_list'])));
        self::assertTrue($path->isApi($this->request('/somewhere/else', ['_route' => 'itk_api_pages_list'])));
        // the public site is not the admin
        self::assertFalse($path->isAdmin($this->request('/blog', ['_route' => 'itk_page'])));
        self::assertFalse($path->isApi($this->request('/blog', ['_route' => 'itk_mcp'])));
        self::assertFalse($path->isAdmin($this->request('/', ['_route' => 'site_home'])));
        // a route of the site that merely looks alike
        self::assertFalse($path->isAdmin($this->request('/x', ['_route' => 'itk_administrator'])));
    }

    #[Test]
    public function aRequestWithoutARouteIsJudgedByItsPathAndDecodedFirst(): void
    {
        $path = $this->path();

        self::assertTrue($path->isAdmin($this->request('/admin')));
        self::assertTrue($path->isAdmin($this->request('/admin/login')));
        self::assertTrue($path->isAdmin($this->request('/%61dmin/login')), 'the router reads it as /admin');
        self::assertTrue($path->isApi($this->request('/admin/api/pages')));
        self::assertTrue($path->isApi($this->request('/admin/%61pi/pages')));
        self::assertFalse($path->isApi($this->request('/admin/apix')));
        self::assertFalse($path->isApi($this->request('/admin')));
        foreach (['/', '/blog', '/administrator', '/adminx/login', '/mcp', '/blog/admin'] as $site) {
            self::assertFalse($path->isAdmin($this->request($site)), $site);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function goodPaths(): iterable
    {
        yield 'one segment' => ['/cp'];
        yield 'default' => ['/admin'];
        yield 'two segments' => ['/site/panel'];
        yield 'dash, underscore, digits' => ['/cms_2-panel'];
    }

    #[Test]
    #[DataProvider('goodPaths')]
    public function aGoodPathIsTakenAsItIs(string $value): void
    {
        $kernel = new TestKernel('test', true, $value);
        $kernel->boot();

        try {
            self::assertSame($value, $kernel->getContainer()->getParameter('itk_cms.admin_path'));
            self::assertSame(\ltrim($value, '/'), $kernel->getContainer()->getParameter('itk_cms.admin_path_segments'));
        } finally {
            $kernel->shutdown();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'no leading slash' => ['admin'];
        yield 'only a slash' => ['/'];
        yield 'trailing slash' => ['/admin/'];
        yield 'double slash' => ['/admin//panel'];
        yield 'leading double slash' => ['//admin'];
        yield 'space' => ['/ad min'];
        yield 'percent (the router reads %name% as a parameter)' => ['/100%'];
        yield 'dots' => ['/../etc'];
        yield 'query' => ['/admin?x=1'];
    }

    #[Test]
    #[DataProvider('badPaths')]
    public function aBadPathIsRefusedWhenTheSiteStarts(string $value): void
    {
        $kernel = new TestKernel('test', true, $value);

        try {
            $kernel->boot();
            self::fail('admin_path "' . $value . '" must be refused');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString('admin_path', $e->getMessage());
        } finally {
            $kernel->shutdown();
        }
    }
}

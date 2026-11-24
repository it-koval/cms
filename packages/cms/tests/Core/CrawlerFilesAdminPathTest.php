<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** robots.txt по умолчанию закрывает ту админку, что есть на самом деле: itk_cms.admin_path = /cp и prefix маршрутов. */
final class CrawlerFilesAdminPathTest extends WebTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, $options['admin'] ?? '/cp', $options['prefix'] ?? '');
    }

    #[Test]
    public function theStandardTextFollowsTheOptionOfTheAdminAddress(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("\nDisallow: /cp/\n", (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('/admin/', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function theStandardTextFollowsThePrefixOfTheRoutes(): void
    {
        static::ensureKernelShutdown();
        $client = static::createClient(['admin' => '/panel', 'prefix' => '/cms']);
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("\nDisallow: /cms/panel/\n", (string) $client->getResponse()->getContent());
    }
}

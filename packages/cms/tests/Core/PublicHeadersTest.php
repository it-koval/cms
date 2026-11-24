<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\PageService;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Публичные страницы ядра: nosniff и Referrer-Policy (опция itk_cms.security_headers, по умолчанию включена). */
final class PublicHeadersTest extends WebTestCase
{
    /** @var list<string> */
    private array $created = [];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        $pages = self::getContainer()->get(PageService::class);
        foreach ($this->created as $id) {
            try {
                $pages->remove($id);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function publish(string $url): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Заголовки', 'url' => $url]);
        $this->created[] = $page['id'];
        $pages->publish($page['id']);
    }

    /** @param array<string, mixed> $options */
    private function client(array $options = []): KernelBrowser
    {
        return static::createClient($options);
    }

    #[Test]
    public function aLivePageAndTheSitemapCarryTheHeaders(): void
    {
        $client = $this->client();
        $this->publish('/test-headers-live');

        foreach (['/test-headers-live', '/no-such-page-test', '/sitemap.xml'] as $path) {
            $client->request('GET', $path);
            $headers = $client->getResponse()->headers;
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
            self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $path);
        }
    }

    #[Test]
    public function theOptionSwitchesThemOff(): void
    {
        $client = $this->client(['itk' => ['security_headers' => false]]);
        $this->publish('/test-headers-off');

        $client->request('GET', '/test-headers-off');

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('X-Content-Type-Options'));
        self::assertFalse($client->getResponse()->headers->has('Referrer-Policy'));
    }

    #[Test]
    public function theAdminKeepsItsOwnHeaders(): void
    {
        $client = $this->client();

        $client->request('GET', '/admin/login');

        self::assertSame('same-origin', $client->getResponse()->headers->get('Referrer-Policy'), 'the admin listener sets its stricter value, the public one leaves the admin alone');
    }
}

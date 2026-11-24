<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\PageService;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

final class BundleAssetsIntegrationTest extends WebTestCase
{
    private array $pages = [];
    private array $emails = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', $options['framework'] ?? []);
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            foreach ($this->pages as $id) {
                self::getContainer()->get(PageService::class)->remove($id);
            }
            foreach ($this->emails as $email) {
                self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM itk_user WHERE email = ?', [$email]);
            }
        }
        parent::tearDown();
    }

    private function assertAssets(array $options, string $expectedBase): void
    {
        $client = self::createClient($options, [
            'SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/public/index.php', 'PHP_SELF' => '/sub/index.php',
        ]);
        $client->disableReboot();
        $client->request('GET', '/sub/admin/login');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('url(' . $expectedBase . 'admin/assets/golos-text-', (string) $client->getResponse()->getContent());
        $email = 'f2-assets-' . \bin2hex(\random_bytes(5)) . '@example.test';
        $this->emails[] = $email;
        $client->loginUser(self::getContainer()->get(UserManager::class)->create($email, 'Assets', 'admin', 'test-password-assets'), 'itk_admin');
        $client->request('GET', '/sub/admin');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"assetsBase":"' . $expectedBase . 'admin/', $html);
        self::assertMatchesRegularExpression('~<script type="module" src="' . \preg_quote($expectedBase, '~') . 'admin/[^" ]+">~', $html);
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="' . \preg_quote($expectedBase, '~') . 'admin/[^" ]+">~', $html);
        $page = self::getContainer()->get(PageService::class)->create(['title' => 'Asset preview', 'url' => '/f2-assets-' . \bin2hex(\random_bytes(5))]);
        $this->pages[] = $page['id'];
        $client->request('GET', '/sub/admin/preview/' . $page['id']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="' . $expectedBase . 'preview.css', $html);
        self::assertStringContainsString('src="' . $expectedBase . 'preview.js', $html);
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([
            new ArrayLoader(['pages/default.html.twig' => '<!doctype html><html><head></head><body>{% include "@ItkCms/preview/_markers.html.twig" %}</body></html>']),
            $twig->getLoader(),
        ]));
        $client->request('GET', '/sub/admin/preview/' . $page['id']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, \substr_count($html, $expectedBase . 'preview.js'), 'the marker include and automatic injection agree');
        self::assertSame(1, \substr_count($html, $expectedBase . 'preview.css'));
    }

    #[Test]
    public function loginShellAndPreviewWorkInASubdirectoryWithoutTheAssetComponent(): void
    {
        $this->assertAssets([], '/sub/bundles/itkcms/');
    }

    #[Test]
    public function loginShellAndPreviewUseTheSymfonyCdnPackage(): void
    {
        if (!\class_exists(Packages::class)) {
            self::markTestSkipped('Symfony Asset is an optional site component.');
        }
        $this->assertAssets(['framework' => ['assets' => ['base_urls' => ['https://cdn.example.test/static'], 'version' => 'f2']]], 'https://cdn.example.test/static/bundles/itkcms/');
    }
}

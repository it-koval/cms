<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ContentHttpRegressionTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf;
    private string $email;
    private array $pages = [];
    private array $items = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: ['schema_file' => __DIR__ . '/Fixture/schema_field_validation.php']);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->email = 'test-content-http-' . \bin2hex(\random_bytes(6)) . '@example.com';
        $user = self::getContainer()->get(UserManager::class)->create($this->email, 'Test', 'admin', 'test-password');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $matches);
        $this->csrf = $matches[1];
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->pages as $id) {
            $db->executeStatement('DELETE FROM itk_page WHERE id = ?', [$id]);
            $db->executeStatement('DELETE FROM itk_revision WHERE resource_id = ?', [$id]);
        }
        foreach ($this->items as $id) {
            $db->executeStatement('DELETE FROM itk_collection_item WHERE id = ?', [$id]);
            $db->executeStatement('DELETE FROM itk_revision WHERE resource_id = ?', [$id]);
        }
        $db->executeStatement('DELETE FROM itk_user WHERE email = ?', [$this->email]);
        parent::tearDown();
    }

    private function json(string $method, string $path, array $data): array
    {
        $this->client->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], \json_encode($data, \JSON_THROW_ON_ERROR));

        return \json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function camelCaseCollectionAndFormAreReachableAndUseTheSharedValidation(): void
    {
        $item = $this->json('POST', '/admin/api/collections/faqItems', ['title' => 'Valid']);
        self::assertResponseStatusCodeSame(201);
        $this->items[] = $item['id'];
        $this->client->request('GET', '/admin/api/collections/faqItems');
        self::assertResponseIsSuccessful();
        foreach ([['name' => 'longer'], ['date' => 'tomorrow'], ['state' => 'closed']] as $bad) {
            $this->client->request('POST', '/request/faqItems', $bad);
            self::assertResponseStatusCodeSame(422);
        }
        $this->json('PATCH', '/admin/api/collections/faqItems/' . $item['id'], ['title' => 'longer']);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function invalidPagePatchKeepsThePreviousDraft(): void
    {
        $service = self::getContainer()->get(PageService::class);
        $page = $service->create(['title' => 'Valid', 'url' => '/test-http-' . \bin2hex(\random_bytes(6))]);
        $this->pages[] = $page['id'];
        foreach ([['title' => ' '], ['url' => "/invalid\taddress"], ['url' => '/invalid?query']] as $bad) {
            $this->json('PATCH', '/admin/api/pages/' . $page['id'], $bad);
            self::assertResponseStatusCodeSame(422);
            self::getContainer()->get(EntityManagerInterface::class)->clear();
            $stored = self::getContainer()->get(EntityManagerInterface::class)->find(Page::class, $page['id'])->translation('ru')->draft;
            self::assertSame($page['title'], $stored['title']);
            self::assertSame($page['url'], $stored['url']);
        }
    }
}

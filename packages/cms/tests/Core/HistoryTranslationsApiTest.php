<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Translation history uses a site with explicitly configured content locales. */
final class HistoryTranslationsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    private string $userId;
    /** @var list<string> */
    private array $ids = [];
    /** @var list<string> */
    private array $itemIds = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: [
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Русский'], 'en' => ['name' => 'English']], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new TripsCollection());
        $user = self::getContainer()->get(UserManager::class)->create('test-hist-translations-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Тест истории переводов', 'admin', 'password-for-test');
        $this->userId = $user->id;
        $this->client->loginUser($user, 'itk_admin');
        $this->csrf = (string) $this->api('GET', 'me')['csrf'];
        self::assertNotSame('', $this->csrf);
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id IN (:ids)')->setParameter('ids', $this->ids ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.id IN (:ids)')->setParameter('ids', $this->itemIds ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', \array_merge($this->ids, $this->itemIds) ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id = :id')->setParameter('id', $this->userId)->execute();
        parent::tearDown();
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[Test]
    public function aTranslationDeletionIsAnAuditEntryAndCannotBeRestored(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Test translation history', 'url' => '/test-http-translation-history'], 201);
        $this->ids[] = $page['id'];
        $item = $this->api('POST', 'collections/test_trips', ['title' => 'Test translation history'], 201);
        $this->itemIds[] = $item['id'];

        foreach (['page' => 'pages/' . $page['id'], 'item' => 'collections/test_trips/' . $item['id']] as $kind => $path) {
            $id = 'page' === $kind ? $page['id'] : $item['id'];
            $this->api('POST', $path . '/translations?locale=en', ['from' => 'ru'], 201);
            $this->api('DELETE', $path . '/translations/en');
            $latest = $this->api('GET', 'history/' . $kind . '/' . $id . '?locale=en')['items'][0];
            self::assertSame('delete_locale', $latest['action']);

            $entry = $this->api('GET', 'history/revision/' . $latest['id']);
            self::assertFalse($entry['current'], 'A deletion snapshot is not the current version.');
            self::assertFalse($entry['canRestore']);
            self::assertFalse($entry['inTrash']);
            self::assertSame([], $entry['against']);
            $this->api('POST', 'history/revision/' . $entry['id'] . '/restore', [], 422);

            $draft = $this->api('GET', $path . ('page' === $kind ? '/draft' : '') . '?locale=en');
            self::assertFalse($draft['translated']);
            self::assertNull($draft[$kind]);
            self::assertSame('Test translation history', $this->api('GET', $path . ('page' === $kind ? '/draft' : ''))[$kind]['title']);
        }
    }
}

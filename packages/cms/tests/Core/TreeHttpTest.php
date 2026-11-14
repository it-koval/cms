<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Entity\User;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\TreeCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Дерево и виды списка по HTTP: PUT collections/{col}/tree и pages/tree проверяют права, схема отдаёт виды, доска меняет поле обычным update. */
final class TreeHttpTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new TreeCollection());
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-tree@example.com', 'Дерево', 'admin', 'password-for-test'));
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_tree')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k')->setParameter('k', 'test%')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
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
    public function theTreeEndpointMovesRecordsAndTheListSaysWhoseChildEachIs(): void
    {
        $collections = self::getContainer()->get(CollectionService::class);
        $a = $collections->create('test_tree', ['title' => 'A']);
        $b = $collections->create('test_tree', ['title' => 'B']);

        $answer = $this->api('PUT', 'collections/test_tree/tree', [['id' => $b['id'], 'parentId' => $a['id'], 'position' => 0]]);
        self::assertSame(['ok' => true, 'moved' => 1], $answer);

        $list = $this->api('GET', 'collections/test_tree?sort=position&envelope=1');
        $parents = [];
        foreach ($list['items'] as $row) {
            $parents[$row['title']] = $row['parentId'];
        }
        self::assertSame(['A' => null, 'B' => $a['id']], $parents);

        $this->api('PUT', 'collections/test_tree/tree', [['id' => $a['id'], 'parentId' => $b['id'], 'position' => 0]], 422);
        $this->api('PUT', 'collections/test_tree/tree', ['nodes' => [['id' => $b['id'], 'parentId' => null, 'position' => 0]]]);
        $this->api('PUT', 'collections/tours/tree', [], 422);
        $this->api('PUT', 'collections/no_such/tree', [], 404);
    }

    #[Test]
    public function aPersonWithoutTheRightCannotMoveRecordsOrPages(): void
    {
        $collections = self::getContainer()->get(CollectionService::class);
        $a = $collections->create('test_tree', ['title' => 'A']);
        $b = $collections->create('test_tree', ['title' => 'B']);
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-limited@example.com', 'Узкий', 'requests', 'password-for-test'));

        $this->api('PUT', 'collections/test_tree/tree', [['id' => $b['id'], 'parentId' => $a['id'], 'position' => 0]], 403);
        $this->api('PUT', 'pages/tree', [['id' => 'x', 'parentId' => null, 'position' => 0]], 403);

        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-admin2@example.com', 'Админ', 'admin', 'password-for-test'));
        self::assertNull($this->api('GET', 'collections/test_tree/' . $b['id'])['item']['parentId'], 'nothing moved');
    }

    #[Test]
    public function theSchemaCarriesTheViewsAndTheTreeFlag(): void
    {
        $schema = $this->api('GET', 'schema');
        $c = null;
        foreach ($schema['collections'] as $row) {
            if ('test_tree' === $row['key']) {
                $c = $row;
            }
        }

        self::assertNotNull($c);
        self::assertTrue($c['tree']);
        self::assertSame([
            ['type' => 'table'],
            ['type' => 'calendar', 'start' => 'start', 'end' => 'end', 'title' => 'title'],
            ['type' => 'kanban', 'field' => 'stage', 'columns' => [['todo', 'Сделать'], ['doing', 'В работе'], ['done', 'Готово']]],
        ], $c['views']);
    }

    #[Test]
    public function theBoardAndTheCalendarChangeARecordWithAnOrdinaryUpdate(): void
    {
        $item = self::getContainer()->get(CollectionService::class)->create('test_tree', ['title' => 'Задача', 'start' => '2026-11-05', 'end' => '2026-11-07', 'stage' => 'todo']);
        TreeCollection::$log = [];

        $this->api('PUT', 'collections/test_tree/' . $item['id'], ['stage' => 'doing']);
        $this->api('PUT', 'collections/test_tree/' . $item['id'], ['start' => '2026-11-06', 'end' => '2026-11-08']);

        $got = $this->api('GET', 'collections/test_tree/' . $item['id'])['item'];
        self::assertSame(['doing', '2026-11-06', '2026-11-08'], [$got['stage'], $got['start'], $got['end']]);
        self::assertSame(['method:beforeUpdating:stage', 'method:afterUpdated:Задача', 'method:beforeUpdating:start,end', 'method:afterUpdated:Задача'], TreeCollection::$log, 'the hooks of an update run');
    }
}

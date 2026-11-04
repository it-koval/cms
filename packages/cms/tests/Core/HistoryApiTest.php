<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** История и корзина по HTTP: маршруты, права, возврат версии и страницы из корзины. */
final class HistoryApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new TripsCollection());
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-hist@example.com', 'Тест истории', 'admin', 'password-for-test'));
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id IN (:ids)')->setParameter('ids', [] === $this->ids ? ['-'] : $this->ids)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_trips')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k OR r.resourceId IN (:ids) OR r.userName = :u')
            ->setParameter('k', 'test%')->setParameter('ids', [] === $this->ids ? ['-'] : $this->ids)->setParameter('u', 'Тест истории')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key LIKE :k')->setParameter('k', 'test-hist-%')->execute();
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
    public function aPageHistoryIsListedOpenedAndReturnedOverHttp(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Test http', 'url' => '/test-http-1'], 201);
        $this->ids[] = $page['id'];
        $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'Test http 2']);
        $this->api('POST', 'pages/' . $page['id'] . '/publish');
        $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'Test http 3']);

        $list = $this->api('GET', 'history/page/' . $page['id']);
        self::assertSame(['edit', 'publish', 'edit', 'create'], \array_column($list['items'], 'action'), 'новые сверху');
        self::assertSame(4, $list['total']);
        self::assertSame(100, $list['keep']);
        self::assertSame('Тест истории', $list['items'][0]['userName']);
        self::assertSame('Test http 3', $list['items'][0]['title']);
        self::assertArrayNotHasKey('snapshot', $list['items'][0], 'вся версия наружу не уходит');

        $paged = $this->api('GET', 'history/page/' . $page['id'] . '?limit=2&offset=3');
        self::assertCount(1, $paged['items']);

        $entry = $this->api('GET', 'history/revision/' . $list['items'][2]['id']);
        self::assertSame('Test http 2', $entry['title']);
        self::assertFalse($entry['current']);
        self::assertSame('Test http 2', $entry['against'][0]['val']);
        self::assertSame('Test http 3', $entry['against'][0]['old']);

        $done = $this->api('POST', 'history/revision/' . $entry['id'] . '/restore');
        self::assertTrue($done['ok']);
        self::assertSame('version', $done['restored']);
        self::assertSame('Test http 2', $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['title']);
        self::assertSame('restore', $this->api('GET', 'history/page/' . $page['id'])['items'][0]['action']);
    }

    #[Test]
    public function aDeletedPageIsInTheTrashUntilItIsRestored(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Test http корзина', 'url' => '/test-http-2'], 201);
        $this->ids[] = $page['id'];
        $this->api('DELETE', 'pages/' . $page['id']);

        $trash = $this->api('GET', 'history/trash?kind=page');
        $mine = \array_values(\array_filter($trash['items'], static fn (array $r): bool => $r['resourceId'] === $page['id']));
        self::assertCount(1, $mine);
        self::assertSame('Test http корзина', $mine[0]['title']);
        self::assertTrue($mine[0]['canRestore']);

        $this->api('GET', 'pages/' . $page['id'] . '/draft', [], 404);
        $this->api('POST', 'history/revision/' . $mine[0]['id'] . '/restore');
        self::assertSame('Test http корзина', $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['title']);
        self::assertSame([], \array_filter($this->api('GET', 'history/trash')['items'], static fn (array $r): bool => $r['resourceId'] === $page['id']));
        $this->api('POST', 'history/revision/' . $mine[0]['id'] . '/restore', [], 409);
        $this->api('GET', 'history/trash?kind=settings', [], 422);
    }

    #[Test]
    public function recordsAndTheirTrashWorkOverHttp(): void
    {
        $item = $this->api('POST', 'collections/test_trips', ['title' => 'Test http поездка'], 201);
        $this->api('PUT', 'collections/test_trips/' . $item['id'], ['seats' => 4]);
        $list = $this->api('GET', 'history/item/' . $item['id']);
        self::assertSame(['edit', 'create'], \array_column($list['items'], 'action'));
        self::assertSame('test_trips', $list['items'][0]['key']);

        $this->api('DELETE', 'collections/test_trips/' . $item['id']);
        $this->api('GET', 'history/item/' . $item['id']);
        $trash = $this->api('GET', 'history/trash?kind=item&key=test_trips');
        self::assertCount(1, $trash['items']);
        $this->api('POST', 'history/revision/' . $trash['items'][0]['id'] . '/restore');
        self::assertSame(4, $this->api('GET', 'collections/test_trips/' . $item['id'])['item']['seats']);
    }

    #[Test]
    public function aPersonWithoutRightsGetsNoHistory(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Test http права', 'url' => '/test-http-3'], 201);
        $this->ids[] = $page['id'];
        $first = $this->api('GET', 'history/page/' . $page['id'])['items'][0]['id'];
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-req@example.com', 'Менеджер заявок', 'requests', 'password-for-test'));

        $this->api('GET', 'history/page/' . $page['id'], [], 403);
        $this->api('GET', 'history/revision/' . $first, [], 403);
        $this->api('POST', 'history/revision/' . $first . '/restore', [], 403);
        $this->api('GET', 'history/settings/test_settings', [], 403);
        $this->api('GET', 'history/menu/header', [], 403);
        self::assertSame([], $this->api('GET', 'history/trash')['items']);
    }

    /** @param list<string> $templates */
    private function signInLimited(string $name, array $templates): void
    {
        $role = new Role('test-hist-' . $name, 'Test ' . $name);
        $role->perms = ['pages' => ['view', 'edit']];
        $role->templates = $templates;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-hist-' . $name . '@example.com', 'Тест ' . $name, $role->key, 'password-for-test'));
    }

    /** @return array{0: string, 1: int} the page that was made as «default» and became «blog», and its oldest entry */
    private function pageWhoseTemplateChanged(string $url): array
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Test шаблон сменился', 'url' => $url], 201);
        $this->ids[] = $page['id'];
        $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'Test шаблон сменился 2']);
        $this->api('PATCH', 'pages/' . $page['id'], ['template' => 'blog']);
        $oldest = \array_pop($this->api('GET', 'history/page/' . $page['id'])['items']);
        self::assertSame('default', $oldest['key'], 'the entry was made under the old template');

        return [$page['id'], $oldest['id']];
    }

    #[Test]
    public function aPersonWithRightsOnlyOnTheOldTemplateGetsNoEntryOfAPageThatChangedIt(): void
    {
        [$page, $entry] = $this->pageWhoseTemplateChanged('/test-http-tpl-1');
        $this->signInLimited('old', ['default']);

        $this->api('GET', 'history/page/' . $page, [], 403);
        $this->api('GET', 'history/revision/' . $entry, [], 403);
        $this->api('POST', 'history/revision/' . $entry . '/restore', [], 403);
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-hist-admin@example.com', 'Админ', 'admin', 'password-for-test'));
        self::assertSame('Test шаблон сменился 2', $this->api('GET', 'pages/' . $page . '/draft')['page']['title'], 'nothing was restored');
    }

    #[Test]
    public function aPersonWithRightsOnlyOnTheNewTemplateCannotReachTheEntriesMadeUnderTheOldOne(): void
    {
        [$page, $entry] = $this->pageWhoseTemplateChanged('/test-http-tpl-2');
        $this->signInLimited('new', ['blog']);

        $this->api('GET', 'history/page/' . $page);
        $this->api('GET', 'history/revision/' . $entry, [], 403);
        $this->api('POST', 'history/revision/' . $entry . '/restore', [], 403);
        $summaries = \array_column($this->api('GET', 'history/page/' . $page)['items'], 'summary', 'id');
        self::assertSame([], $summaries[$entry], 'the changes made under a template the person has no right to are not described to him');
    }

    #[Test]
    public function aPersonWithRightsOnBothTemplatesStillReturnsTheEntry(): void
    {
        [$page, $entry] = $this->pageWhoseTemplateChanged('/test-http-tpl-3');
        $this->signInLimited('both', ['default', 'blog']);

        $this->api('GET', 'history/revision/' . $entry);
        $this->api('POST', 'history/revision/' . $entry . '/restore');
        self::assertSame('Test шаблон сменился', $this->api('GET', 'pages/' . $page . '/draft')['page']['title']);
    }

    #[Test]
    public function aDeletedPageIsCheckedByTheTemplateOfTheEntry(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Test корзина права', 'url' => '/test-http-tpl-4'], 201);
        $this->ids[] = $page['id'];
        $this->api('DELETE', 'pages/' . $page['id']);
        $this->signInLimited('trash', ['default']);

        $trash = $this->api('GET', 'history/trash');
        $entry = \array_values(\array_filter($trash['items'], static fn (array $i): bool => $i['resourceId'] === $page['id']))[0];
        self::assertTrue($entry['canRestore']);
        $this->api('POST', 'history/revision/' . $entry['id'] . '/restore');
    }

    #[Test]
    public function aTemplateChangeStartsANewEntryInsteadOfJoiningTheAutosaves(): void
    {
        [$page] = $this->pageWhoseTemplateChanged('/test-http-tpl-6');

        self::assertSame('blog', $this->api('GET', 'history/page/' . $page)['items'][0]['key'], 'the newest entry holds the page under «blog»');
    }

    #[Test]
    public function aDeletedPageKeepsTheTemplateItHadLast(): void
    {
        [$page, $entry] = $this->pageWhoseTemplateChanged('/test-http-tpl-5');
        $this->api('DELETE', 'pages/' . $page);
        $this->signInLimited('gone', ['default']);

        $this->api('GET', 'history/revision/' . $entry, [], 403);
        $this->api('POST', 'history/revision/' . $entry . '/restore', [], 403);
    }

    #[Test]
    public function unknownThingsAre404(): void
    {
        $this->api('GET', 'history/page/test-no-such-page', [], 404);
        $this->api('GET', 'history/revision/999999999', [], 404);
        $this->api('POST', 'history/revision/999999999/restore', [], 404);
        $this->api('GET', 'history/robot/1', [], 404);
    }
}

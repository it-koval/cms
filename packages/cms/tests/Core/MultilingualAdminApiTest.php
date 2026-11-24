<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\User;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class MultilingualAdminApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    private string $userId;
    /** @var list<string> */
    private array $ids = [];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: ['locales' => [
            'ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'], 'en' => ['name' => 'English'], // i18n-ignore: configured language names
        ]]);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new TripsCollection());
        $user = self::getContainer()->get(UserManager::class)->create('test-admin-locales-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Test', 'admin', 'password-for-test');
        $this->userId = $user->id;
        $this->client->loginUser($user, 'itk_admin');
        $this->csrf = $this->api('GET', 'me')['csrf'];
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['Page', 'CollectionItem', 'Revision'] as $entity) {
            $field = 'Revision' === $entity ? 'resourceId' : 'id';
            $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\' . $entity . ' e WHERE e.' . $field . ' IN (:ids)')->setParameter('ids', $this->ids ?: ['-'])->execute();
        }
        $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.id = :id')->setParameter('id', $this->userId)->execute();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string|int, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return \json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function item(string $title): string
    {
        $id = $this->api('POST', 'collections/test_trips', ['title' => $title], 201)['id'];
        $this->ids[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function overview(string $query = ''): array
    {
        return \array_column($this->api('GET', 'collections' . $query), null, 'key')['test_trips'];
    }

    #[Test]
    public function collectionCountersIncludeEveryEnabledLanguageAndCountExistingVersionsOnly(): void
    {
        $one = $this->item('One');
        $this->item('Two');
        $service = self::getContainer()->get(CollectionService::class);
        $service->publish('test_trips', $one);
        $this->api('POST', 'collections/test_trips/' . $one . '/translations?locale=kk', ['from' => 'ru'], 201);
        $service->publish('test_trips', $one, 'kk');
        $service->update('test_trips', $one, ['title' => 'Changed KK'], 'kk');
        $this->api('POST', 'collections/test_trips/' . $one . '/translations?locale=zh', ['from' => 'ru'], 201);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->find(CollectionItem::class, $one);
        $entity->translations['de'] = $entity->translations['zh'];
        $em->flush();

        $counts = $this->overview();
        self::assertSame(2, $counts['count']);
        self::assertSame(3, $counts['unpublished']);
        self::assertSame([
            'ru' => ['count' => 2, 'unpublished' => 1],
            'kk' => ['count' => 1, 'unpublished' => 1],
            'tr' => ['count' => 0, 'unpublished' => 0],
            'zh' => ['count' => 1, 'unpublished' => 1],
            'en' => ['count' => 0, 'unpublished' => 0],
        ], $counts['locales']);
        self::assertSame(1, $this->overview('?locale=kk')['unpublished']);
        self::assertSame($counts['locales'], $this->overview('?locale=kk')['locales']);
    }

    #[Test]
    public function matrixCountsKeepColumnTotalsAndUseTheDomainChangeDetectorForEachLanguage(): void
    {
        $id = $this->item('Matrix');
        self::getContainer()->get(Schema::class)->collection('test_trips')->singleton($id)->matrix(['colFields' => [], 'rowKinds' => [], 'rowLabel' => 'Row']);
        $service = self::getContainer()->get(CollectionService::class);
        $service->update('test_trips', $id, ['cols' => [['id' => 'one', 'title' => 'One'], ['id' => 'two', 'title' => 'Two']], 'rows' => [['_id' => 'old', 'kind' => 'text', 'label' => 'Same']]]);
        $service->publish('test_trips', $id);
        $service->update('test_trips', $id, ['rows' => [['_id' => 'new', 'kind' => 'text', 'label' => 'Same']]]);
        $this->api('POST', 'collections/test_trips/' . $id . '/translations?locale=kk', ['from' => 'ru'], 201);
        $service->publish('test_trips', $id, 'kk');
        $service->update('test_trips', $id, ['cols' => [['id' => 'one', 'title' => 'Changed']]], 'kk');

        $counts = $this->overview();
        self::assertSame(2, $counts['count']);
        self::assertSame(['count' => 1, 'unpublished' => 0], $counts['locales']['ru']);
        self::assertSame(['count' => 1, 'unpublished' => 1], $counts['locales']['kk']);
        self::assertSame(1, $counts['unpublished']);
        $row = \array_values(\array_filter($this->api('GET', 'dashboard')['unpublished'], static fn (array $r): bool => $r['id'] === $id && 'kk' === $r['locale']))[0];
        self::assertSame('/collections/test_trips?lang=kk', $row['to']);
    }

    #[Test]
    public function todayCountsAndLinksIncludeEnabledVersionsAndExcludeDisabledOnes(): void
    {
        $before = $this->api('GET', 'dashboard');
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Locale dashboard', 'url' => '/test-dashboard-' . \bin2hex(\random_bytes(5))], 201);
        $this->ids[] = $id = $page['id'];
        $this->api('POST', 'pages/' . $id . '/translations?locale=kk', ['from' => 'ru'], 201);
        $pages = self::getContainer()->get(PageService::class);
        $pages->publish($id);
        $pages->publish($id, 'kk');
        $pages->saveDraft($id, 'kk', ['title' => 'KK changed']);
        $pages->submit($id, 'kk');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $p = $em->find(Page::class, $id);
        $disabled = new PageTranslation($p, 'de');
        $disabled->draft = $p->translation('ru')->draft;
        $p->translations->set('de', $disabled);
        $em->persist($disabled);
        $em->flush();
        $itemId = $this->item('Dashboard item');
        $this->api('POST', 'collections/test_trips/' . $itemId . '/translations?locale=zh', ['from' => 'ru'], 201);

        $dashboard = $this->api('GET', 'dashboard');
        self::assertSame(['ru', 'kk', 'tr', 'zh', 'en'], \array_keys($dashboard['locales']));
        self::assertSame($before['locales']['ru']['pages']['count'] + 1, $dashboard['locales']['ru']['pages']['count']);
        self::assertSame($before['locales']['kk']['pages']['count'] + 1, $dashboard['locales']['kk']['pages']['count']);
        self::assertSame($before['locales']['kk']['pages']['unpublished'] + 1, $dashboard['locales']['kk']['pages']['unpublished']);
        self::assertSame($before['locales']['zh']['collections']['count'] + 1, $dashboard['locales']['zh']['collections']['count']);
        $rows = \array_values(\array_filter($dashboard['unpublished'], static fn (array $r): bool => $r['id'] === $id));
        self::assertSame(['kk'], \array_column($rows, 'locale'));
        self::assertSame('/pages/' . $id . '?lang=kk', $rows[0]['to']);
        self::assertSame('kk', \array_values(\array_filter($dashboard['awaiting'], static fn (array $r): bool => $r['id'] === $id))[0]['locale']);
        self::assertSame('ru', \array_values(\array_filter($dashboard['recent'], static fn (array $r): bool => $r['to'] === '/pages/' . $id . '?lang=ru'))[0]['locale']);
    }

    #[Test]
    public function languageCountersAndAllLanguageHistoryRespectContentPermissions(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'Restricted', 'url' => '/test-restricted-' . \bin2hex(\random_bytes(6))], 201);
        $this->ids[] = $page['id'];
        $item = $this->item('Restricted item');
        $revision = $this->api('GET', 'history/item/' . $item)['items'][0]['id'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->userId);
        $user->role = 'requests';
        $em->flush();
        $this->client->loginUser($user, 'itk_admin');

        foreach ($this->api('GET', 'dashboard')['locales'] as $stats) {
            self::assertSame(['pages' => ['count' => 0, 'unpublished' => 0], 'collections' => ['count' => 0, 'unpublished' => 0]], $stats);
        }
        self::assertSame([], $this->api('GET', 'dashboard')['unpublished']);
        self::assertSame([], $this->api('GET', 'collections'));
        $this->api('GET', 'history/page/' . $page['id'] . '?scope=all', [], 403);
        $this->api('GET', 'history/item/' . $item . '?scope=all', [], 403);
        $this->api('POST', 'history/revision/' . $revision . '/restore', [], 403);
    }
}

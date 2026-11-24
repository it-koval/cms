<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\QueryCounter;
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
    private string $settingsKey;
    /** @var list<string> */
    private array $ids = [];
    /** @var list<string> */
    private array $itemIds = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: [
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'en' => ['name' => 'English']], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new TripsCollection());
        $this->settingsKey = 'test_history_' . \bin2hex(\random_bytes(6));
        self::getContainer()->get(ResourceRegistry::class)->add(new HistoryLocaleSettings($this->settingsKey));
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
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.userId = :id')->setParameter('id', $this->userId)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\MenuArea a WHERE a.key IN (:ids)')->setParameter('ids', $this->ids ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :key')->setParameter('key', $this->settingsKey)->execute();
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
            self::assertNotContains($id, \array_column($this->api('GET', 'history/trash')['items'], 'resourceId'));

            $draft = $this->api('GET', $path . ('page' === $kind ? '/draft' : '') . '?locale=en');
            self::assertFalse($draft['translated']);
            self::assertNull($draft[$kind]);
            self::assertSame('Test translation history', $this->api('GET', $path . ('page' === $kind ? '/draft' : ''))[$kind]['title']);
        }
    }

    #[Test]
    public function allLanguagesArePagedTogetherAndCurrentIsDeterminedForEachLanguage(): void
    {
        $page = $this->api('POST', 'pages', ['template' => 'default', 'title' => 'History RU', 'url' => '/test-history-all-' . \bin2hex(\random_bytes(6))], 201);
        $this->ids[] = $page['id'];
        $item = $this->api('POST', 'collections/test_trips', ['title' => 'History RU'], 201);
        $this->itemIds[] = $item['id'];

        foreach (['page' => 'pages/' . $page['id'], 'item' => 'collections/test_trips/' . $item['id']] as $kind => $path) {
            $id = 'page' === $kind ? $page['id'] : $item['id'];
            $this->api('POST', $path . '/translations?locale=kk', ['from' => 'ru'], 201);
            $this->api('POST', $path . '/publish?locale=kk');
            $this->api('POST', $path . '/publish?locale=ru');
            if ('page' === $kind) {
                self::getContainer()->get(PageService::class)->saveDraft($id, 'kk', ['title' => 'Changed KK']);
                self::getContainer()->get(PageService::class)->saveDraft($id, 'ru', ['title' => 'Changed RU']);
            } else {
                self::getContainer()->get(CollectionService::class)->update('test_trips', $id, ['title' => 'Changed KK'], 'kk');
                self::getContainer()->get(CollectionService::class)->update('test_trips', $id, ['title' => 'Changed RU'], 'ru');
            }

            $first = $this->api('GET', 'history/' . $kind . '/' . $id . '?scope=all&limit=3');
            $second = [];
            $queries = QueryCounter::during(function () use ($kind, $id, &$second): void {
                $second = $this->api('GET', 'history/' . $kind . '/' . $id . '?scope=all&limit=3&offset=3');
            });
            self::assertSame(6, $first['total']);
            self::assertSame(6, $second['total']);
            self::assertSame(['ru', 'kk', 'ru'], \array_column($first['items'], 'locale'));
            self::assertSame(['kk', 'kk', 'ru'], \array_column($second['items'], 'locale'));
            self::assertSame([true, true, false], \array_column($first['items'], 'current'));
            self::assertSame([false, false, false], \array_column($second['items'], 'current'));
            $ids = \array_merge(\array_column($first['items'], 'id'), \array_column($second['items'], 'id'));
            $sorted = $ids;
            \rsort($sorted);
            self::assertSame($sorted, $ids);
            self::assertCount(6, \array_unique($ids));
            self::assertNotEmpty(\array_filter($queries, static fn (string $q): bool => \str_contains($q, 'itk_revision') && \str_contains($q, 'LIMIT 3 OFFSET 3')), 'The combined page must be selected in SQL.');
            self::assertSame(['ru', 'ru', 'ru'], \array_column($this->api('GET', 'history/' . $kind . '/' . $id)['items'], 'locale'));
            self::assertSame(['kk', 'kk', 'kk'], \array_column($this->api('GET', 'history/' . $kind . '/' . $id . '?locale=kk')['items'], 'locale'));
            self::assertSame(6, $this->api('GET', 'history/' . $kind . '/' . $id . '?scope=all&locale=ru')['total']);

            $suffix = 'page' === $kind ? '/draft' : '';
            $ruBefore = $this->api('GET', $path . $suffix . '?locale=ru');
            $kkBefore = $this->api('GET', $path . $suffix . '?locale=kk');
            $restored = $this->api('POST', 'history/revision/' . $second['items'][1]['id'] . '/restore');
            self::assertSame('kk', $restored['locale']);
            self::assertSame('version', $restored['restored']);
            $kkAfter = $this->api('GET', $path . $suffix . '?locale=kk');
            self::assertSame('History RU', $kkAfter[$kind]['title']);
            self::assertSame($kkBefore['published'], $kkAfter['published']);
            $ruAfter = $this->api('GET', $path . $suffix . '?locale=ru');
            self::assertSame($ruBefore[$kind], $ruAfter[$kind]);
            self::assertSame($ruBefore['published'], $ruAfter['published']);
        }
    }

    #[Test]
    public function menusAndSettingsSupportTheSameAllLanguageHistoryScope(): void
    {
        $area = $this->api('POST', 'menu-areas', ['name' => 'History menu ' . \bin2hex(\random_bytes(6))], 201);
        $this->ids[] = $key = $area['key'];
        foreach (['ru', 'kk'] as $locale) {
            $this->api('PATCH', 'settings?locale=' . $locale, [$this->settingsKey => 'History ' . $locale]);
            $this->api('PUT', 'menus/' . $key . '?locale=' . $locale, ['items' => [['label' => 'History ' . $locale, 'link' => ['kind' => 'url', 'url' => '/history-' . $locale]]]]);
        }
        foreach (['settings' => $this->settingsKey, 'menu' => $key] as $kind => $id) {
            $all = $this->api('GET', 'history/' . $kind . '/' . $id . '?scope=all');
            self::assertSame(['kk', 'kk', 'ru', 'ru'], \array_column($all['items'], 'locale'));
            self::assertSame([true, false, true, false], \array_column($all['items'], 'current'));
            self::assertSame(4, $all['total']);
            self::assertSame(2, $this->api('GET', 'history/' . $kind . '/' . $id . '?locale=kk')['total']);
        }
    }
}

final class HistoryLocaleSettings extends SettingsResource
{
    public function __construct(private readonly string $resourceKey)
    {
    }

    public function key(): string
    {
        return $this->resourceKey;
    }

    public function title(): string
    {
        return 'History settings';
    }

    public function fields(): iterable
    {
        yield Text::make('History text', $this->resourceKey)->translatable();
    }
}

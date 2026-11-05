<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\User;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use Itk\Cms\Tests\Core\Fixture\PlainTemplate;
use Itk\Cms\Tests\Core\Fixture\TreeCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Оптимистичная проверка версии: автосохранение редактора присылает baseVersion, и если страницу или запись тем временем изменили
 * (ассистент по MCP, другой человек), сервер отвечает 409 `conflict` и ничего не перезаписывает. Без baseVersion всё работает как раньше.
 */
final class EditConflictTest extends WebTestCase
{
    use McpSupport;

    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->mcpBoot();
        self::getContainer()->get(ResourceRegistry::class)->add(new PlainTemplate());
        self::getContainer()->get(ResourceRegistry::class)->add(new TreeCollection());
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-mcp-editor@example.com', 'TestMcp Редактор', 'admin', 'password-for-test'));
    }

    protected function tearDown(): void
    {
        $this->mcpClean();
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_tree')->execute();
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
     * Запрос редактора. Менеджер сущностей очищается перед каждым: ответ всегда строится из того, что лежит в базе.
     *
     * @param array<int|string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array{0: \Itk\Cms\Mcp\McpContext, 1: array<string, mixed>} the assistant's context and a page it made */
    private function assistantAndPage(string $title = 'TestMcp Конфликт'): array
    {
        $user = $this->mcpUser('admin', 'bot');
        $ctx = $this->mcpContext($this->mcpForcedKey($user, [McpScopes::READ, McpScopes::WRITE], 'Ассистент сайта')['token']);

        return [$ctx, $this->mcpPage($ctx, $title)];
    }

    #[Test]
    public function aPageHandsOutItsVersionAndSmallAutosavesOfOneEditorNeverConflict(): void
    {
        [, $page] = $this->assistantAndPage();
        $draft = $this->api('GET', 'pages/' . $page['id'] . '/draft');
        $version = $draft['page']['version'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $version);

        $seen = [$version];
        foreach (['Один', 'Два', 'Три 🦔 ½ "кавычки" / слэш', 'Четыре'] as $title) {
            $saved = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp ' . $title, 'baseVersion' => $version]);
            self::assertArrayNotHasKey('baseVersion', $saved);
            self::assertNotContains($saved['version'], $seen, 'the content changed: so did the version');
            $seen[] = $version = $saved['version'];
            self::assertSame($version, $this->api('GET', 'pages/' . $page['id'])['version'], 'what the save answered is what the next reader sees');
        }
        // the same content saved again changes nothing, and the version stays
        $again = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Четыре', 'baseVersion' => $version]);
        self::assertSame($version, $again['version']);
    }

    #[Test]
    public function aStaleVersionGives409WithWhoChangedItAndTheDataStays(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['version'];

        $edit = $this->mcpCall($ctx, 'page_update', ['id' => $page['id'], 'title' => 'TestMcp Правка ассистента']);
        self::assertTrue($edit['ok'], $edit['text']);

        $history = $this->api('GET', 'history/page/' . $page['id'])['total'];
        $r = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Моя правка', 'baseVersion' => $opened], 409);

        self::assertSame('Страницу изменили, пока она была открыта', $r['error']);
        self::assertSame('conflict', $r['details']['code']);
        $now = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page'];
        self::assertSame($now['version'], $r['details']['current']['version']);
        self::assertSame('mcp', $r['details']['current']['via']);
        self::assertSame('TestMcp admin', $r['details']['current']['by']);
        self::assertSame('Ассистент сайта', $r['details']['current']['viaName']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $r['details']['current']['at']);
        self::assertSame('TestMcp Правка ассистента', $now['title'], 'the data of the assistant is not overwritten');
        self::assertSame($history, $this->api('GET', 'history/page/' . $page['id'])['total'], 'a refused save leaves no history entry');

        // «Оставить мои правки»: the same save with the version the server just named
        $kept = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Моя правка', 'baseVersion' => $r['details']['current']['version']]);
        self::assertSame('TestMcp Моя правка', $kept['title']);
        self::assertSame('TestMcp Моя правка', $this->api('GET', 'pages/' . $page['id'])['title']);
    }

    #[Test]
    public function aSectionAddedByTheAssistantAlsoMakesTheOpenEditorStale(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page'];

        $add = $this->mcpCall($ctx, 'page_section_add', ['page_id' => $page['id'], 'type' => 'text', 'data' => ['text' => '<p>От ассистента</p>']]);
        self::assertTrue($add['ok'], $add['text']);

        $r = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['sections' => $opened['sections'], 'baseVersion' => $opened['version']], 409);
        self::assertSame('conflict', $r['details']['code']);
        self::assertCount(\count($opened['sections']) + 1, $this->api('GET', 'pages/' . $page['id'])['sections'], 'the section of the assistant is still there');
    }

    #[Test]
    public function aRequestWithoutBaseVersionWorksAsBefore(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $this->mcpCall($ctx, 'page_update', ['id' => $page['id'], 'title' => 'TestMcp Чужая правка']);

        $saved = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Без версии']);
        self::assertSame('TestMcp Без версии', $saved['title']);
        $empty = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Пустая версия', 'baseVersion' => '']);
        self::assertSame('TestMcp Пустая версия', $empty['title'], 'an empty baseVersion is no baseVersion');
    }

    #[Test]
    public function publishingDoesNotMakeTheOpenEditorStale(): void
    {
        [, $page] = $this->assistantAndPage();
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['version'];
        $this->api('POST', 'pages/' . $page['id'] . '/publish');

        $saved = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp После публикации', 'baseVersion' => $opened]);
        self::assertSame('TestMcp После публикации', $saved['title'], 'the draft did not change, only its status');
    }

    #[Test]
    public function aBaseVersionThatIsNotATextIsRefused(): void
    {
        [, $page] = $this->assistantAndPage();

        $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp X', 'baseVersion' => ['a']], 422);
    }

    #[Test]
    public function theVersionIsCheckedAgainstTheDatabaseAfterTheLockNotAgainstWhatWasLoadedBefore(): void
    {
        [, $page] = $this->assistantAndPage();
        $pages = self::getContainer()->get(PageService::class);
        $stale = $pages->get($page['id'])['version']; // the page is now in the identity map of this request
        // another process writes the draft: this request still holds the old copy
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE itk_page_translation SET draft = draft || '{\"title\": \"TestMcp Из другого процесса\"}' WHERE page_id = ?", [$page['id']]);

        try {
            $pages->saveDraft($page['id'], null, ['title' => 'TestMcp Устаревшая копия'], $stale);
            self::fail('the save over a newer draft must be refused');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
            self::assertSame('conflict', $e->details()['code']);
        }
        self::assertSame('TestMcp Из другого процесса', self::getContainer()->get(Connection::class)->fetchOne("SELECT draft ->> 'title' FROM itk_page_translation WHERE page_id = ?", [$page['id']]));
    }

    #[Test]
    public function aMoveOfThePageInTheTreeMakesTheOpenEditorStaleToo(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $other = $this->mcpPage($ctx, 'TestMcp Новый родитель');
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page'];
        $this->api('PUT', 'pages/tree', [['id' => $page['id'], 'parentId' => $other['id'], 'position' => 0]]);

        // the editor still thinks the page stands at the top: its autosave would put it back there
        $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Старое место', 'parentId' => $opened['parentId'], 'baseVersion' => $opened['version']], 409);
        self::assertSame($other['id'], $this->api('GET', 'pages/' . $page['id'])['parentId']);
    }

    #[Test]
    public function aRecordHandsOutItsVersionAndRefusesAStaleOne(): void
    {
        $user = $this->mcpUser('admin', 'bot');
        $ctx = $this->mcpContext($this->mcpForcedKey($user, [McpScopes::READ, McpScopes::WRITE], 'Ассистент сайта')['token']);
        $item = self::getContainer()->get(CollectionService::class)->create('test_trips', ['title' => 'TestMcp Поездка', 'seats' => 3, 'state' => 'open']);
        $path = 'collections/test_trips/' . $item['id'];

        $opened = $this->api('GET', $path)['item']['version'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $opened);

        // small autosaves of one editor in a row
        $version = $opened;
        foreach ([4, 5, 6] as $seats) {
            $saved = $this->api('PUT', $path, ['seats' => $seats, 'baseVersion' => $version]);
            self::assertArrayNotHasKey('baseVersion', $saved, 'it is not a field of the record');
            self::assertNotSame($version, $saved['version']);
            $version = $saved['version'];
        }
        self::assertSame($version, $this->api('GET', $path)['item']['version']);

        // the assistant changes the record while the form is open
        $edit = $this->mcpCall($ctx, 'collection_item_update', ['collection' => 'test_trips', 'id' => $item['id'], 'fields' => ['seats' => 9]]);
        self::assertTrue($edit['ok'], $edit['text']);

        $r = $this->api('PUT', $path, ['seats' => 7, 'baseVersion' => $version], 409);
        self::assertSame('Запись изменили, пока она была открыта', $r['error']);
        self::assertSame('conflict', $r['details']['code']);
        self::assertSame('mcp', $r['details']['current']['via']);
        self::assertSame('Ассистент сайта', $r['details']['current']['viaName']);
        self::assertSame('TestMcp admin', $r['details']['current']['by']);
        self::assertSame(9, $this->api('GET', $path)['item']['seats'], 'the value of the assistant stays');
        self::assertSame($r['details']['current']['version'], $this->api('GET', $path)['item']['version']);

        // without a version the old behaviour; with the fresh one the save passes
        self::assertSame(8, $this->api('PUT', $path, ['seats' => 8])['seats']);
        $fresh = $this->api('GET', $path)['item']['version'];
        self::assertSame(7, $this->api('PUT', $path, ['seats' => 7, 'baseVersion' => $fresh])['seats']);
    }

    #[Test]
    public function aChangeOfTheTemplateAloneMakesTheOpenEditorStale(): void
    {
        [, $page] = $this->assistantAndPage();
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page'];
        // test_plain has no fields, like «Обычная страница»: the draft stays as it was, only the template of the page changes
        $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['template' => 'test_plain', 'baseVersion' => $opened['version']]);
        $moved = $this->api('GET', 'pages/' . $page['id']);
        self::assertSame('test_plain', $moved['template']);
        self::assertSame($opened['sections'], $moved['sections'], 'the sections did not change');

        // the second editor still thinks the page is «Обычная страница»: its autosave would put the template back
        $r = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Старый шаблон', 'template' => 'default', 'baseVersion' => $opened['version']], 409);
        self::assertSame('conflict', $r['details']['code']);
        self::assertSame('test_plain', $this->api('GET', 'pages/' . $page['id'])['template']);
    }

    #[Test]
    public function theVersionOfARecordIsCheckedAgainstTheDatabaseAfterTheLock(): void
    {
        $records = self::getContainer()->get(CollectionService::class);
        $item = $records->create('test_trips', ['title' => 'TestMcp Поездка', 'seats' => 3, 'state' => 'open']);
        $stale = $records->get('test_trips', $item['id'])['item']['version']; // the record is now in the identity map of this request
        // another process writes the draft: this request still holds the old copy
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE itk_collection_item SET translations = jsonb_set(translations, '{ru,draft,seats}', '99') WHERE id = ?", [$item['id']]);

        try {
            $records->update('test_trips', $item['id'], ['seats' => 5], null, baseVersion: $stale);
            self::fail('the save over a newer draft must be refused');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
            self::assertSame('conflict', $e->details()['code']);
        }
        self::assertSame('99', self::getContainer()->get(Connection::class)->fetchOne("SELECT translations #> '{ru,draft,seats}' FROM itk_collection_item WHERE id = ?", [$item['id']]));
    }

    #[Test]
    public function aMoveOfARecordInTheTreeMakesTheOpenFormStaleToo(): void
    {
        $records = self::getContainer()->get(CollectionService::class);
        $a = $records->create('test_tree', ['title' => 'TestMcp A']);
        $b = $records->create('test_tree', ['title' => 'TestMcp B']);
        $opened = $this->api('GET', 'collections/test_tree/' . $b['id'])['item'];
        $this->api('PUT', 'collections/test_tree/tree', [['id' => $b['id'], 'parentId' => $a['id'], 'position' => 0]]);

        // the form still thinks the record stands at the top: its autosave would put it back there
        $r = $this->api('PUT', 'collections/test_tree/' . $b['id'], ['title' => 'TestMcp B2', 'parentId' => null, 'baseVersion' => $opened['version']], 409);
        self::assertSame('conflict', $r['details']['code']);
        self::assertSame($a['id'], $this->api('GET', 'collections/test_tree/' . $b['id'])['item']['parentId']);
        self::assertSame('TestMcp B', $this->api('GET', 'collections/test_tree/' . $b['id'])['item']['title']);
    }

    #[Test]
    public function aPublicationAfterTheChangeDoesNotTakeItsPlaceInTheAnswer(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['version'];
        $edit = $this->mcpCall($ctx, 'page_update', ['id' => $page['id'], 'title' => 'TestMcp Правка ассистента']);
        self::assertTrue($edit['ok'], $edit['text']);

        // a person publishes what the assistant wrote: the draft is the assistant's, the newest entry of the history is not
        foreach (['publish', 'unpublish'] as $action) {
            $this->api('POST', 'pages/' . $page['id'] . '/' . $action);
            $r = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Моя правка', 'baseVersion' => $opened], 409);
            self::assertSame('TestMcp admin', $r['details']['current']['by'], $action);
            self::assertSame('mcp', $r['details']['current']['via'], $action);
            self::assertSame('Ассистент сайта', $r['details']['current']['viaName'], $action);
        }
    }

    #[Test]
    public function aMoveInTheTreeIsWrittenToTheHistoryAndTheAnswerNamesWhoMoved(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $other = $this->mcpPage($ctx, 'TestMcp Новый родитель');
        $edit = $this->mcpCall($ctx, 'page_update', ['id' => $page['id'], 'title' => 'TestMcp Правка ассистента']);
        self::assertTrue($edit['ok'], $edit['text']);
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['version'];
        $history = $this->api('GET', 'history/page/' . $page['id'])['total'];

        $this->api('PUT', 'pages/tree', [['id' => $page['id'], 'parentId' => $other['id'], 'position' => 0]]);

        $r = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['title' => 'TestMcp Моя правка', 'baseVersion' => $opened], 409);
        self::assertSame('TestMcp Редактор', $r['details']['current']['by'], 'the person who moved the page, not the assistant who edited it before');
        self::assertNull($r['details']['current']['via']);

        $list = $this->api('GET', 'history/page/' . $page['id']);
        self::assertSame($history + 1, $list['total']);
        self::assertSame('Расположение в дереве страниц', $list['items'][0]['summary'][0]['label']);
    }

    #[Test]
    public function aNewOrderAmongTheSamePagesTouchesNeitherTheVersionNorTheHistory(): void
    {
        [$ctx, $page] = $this->assistantAndPage();
        $this->mcpPage($ctx, 'TestMcp Сосед');
        $opened = $this->api('GET', 'pages/' . $page['id'] . '/draft')['page'];
        $history = $this->api('GET', 'history/page/' . $page['id'])['total'];

        $this->api('PUT', 'pages/tree', [['id' => $page['id'], 'parentId' => $opened['parentId'], 'position' => 0]]);

        self::assertSame($opened['version'], $this->api('GET', 'pages/' . $page['id'])['version']);
        self::assertSame($history, $this->api('GET', 'history/page/' . $page['id'])['total']);
    }

    #[Test]
    public function aMoveOfARecordIsWrittenToTheHistoryAndTheAnswerNamesWhoMoved(): void
    {
        $user = $this->mcpUser('admin', 'bot');
        $ctx = $this->mcpContext($this->mcpForcedKey($user, [McpScopes::READ, McpScopes::WRITE], 'Ассистент сайта')['token']);
        $records = self::getContainer()->get(CollectionService::class);
        $a = $records->create('test_tree', ['title' => 'TestMcp A']);
        $b = $records->create('test_tree', ['title' => 'TestMcp B']);
        $edit = $this->mcpCall($ctx, 'collection_item_update', ['collection' => 'test_tree', 'id' => $b['id'], 'fields' => ['title' => 'TestMcp Правка ассистента']]);
        self::assertTrue($edit['ok'], $edit['text']);
        $opened = $this->api('GET', 'collections/test_tree/' . $b['id'])['item']['version'];
        $history = $this->api('GET', 'history/item/' . $b['id'])['total'];

        $this->api('PUT', 'collections/test_tree/tree', [['id' => $b['id'], 'parentId' => $a['id'], 'position' => 0]]);

        $r = $this->api('PUT', 'collections/test_tree/' . $b['id'], ['title' => 'TestMcp Моя правка', 'baseVersion' => $opened], 409);
        self::assertSame('TestMcp Редактор', $r['details']['current']['by'], 'the person who moved the record, not the assistant who edited it before');
        self::assertNull($r['details']['current']['via']);

        $list = $this->api('GET', 'history/item/' . $b['id']);
        self::assertSame($history + 1, $list['total']);
        self::assertSame('Расположение в дереве', $list['items'][0]['summary'][0]['label']);

        // the same person puts it back: the entry cancels out, like an autosave undone, and the form that was open is right again
        $this->api('PUT', 'collections/test_tree/tree', [['id' => $b['id'], 'parentId' => null, 'position' => 0]]);
        self::assertSame($history, $this->api('GET', 'history/item/' . $b['id'])['total']);
        self::assertSame($opened, $this->api('GET', 'collections/test_tree/' . $b['id'])['item']['version']);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Entity\User;
use Itk\Cms\Extension\NavItem;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\TestTemplate;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** JSON API админки: схема с nav для человека, список с фильтрами, действия, скачивание, настройки пользователя (prefs), оболочка с плагинами. */
final class AdminApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $registry->add(new TripsCollection());
        $registry->add(new TestTemplate());
        $ext = self::getContainer()->get(Registry::class);
        $ext->menu(NavItem::make('Отчёты', '/x/reports')->group('Аналитика', icon: 'chart', order: 60)->icon('chart')->order(5));
        $ext->menu(NavItem::make('Секретный отчёт', '/x/secret')->group('Аналитика')->canSee('set:users'));
        $ext->adminAssets(js: ['/bundles/test/admin.js', 'javascript:alert(1)', '//evil.example/x.js'], css: ['/bundles/test/admin.css']);
        $this->user = self::getContainer()->get(UserManager::class)->create('test-api@example.com', 'Тест API', 'admin', 'password-for-test');
        $this->signIn($this->user);
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_trips')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k')->setParameter('k', 'test%')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent());
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf];
        $this->client->request($method, '/admin/api/' . $path, [], [], $server, [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[Test]
    public function theSchemaCarriesTheMenuBuiltForThePerson(): void
    {
        $schema = $this->api('GET', 'schema');
        $nav = $schema['nav'];

        self::assertSame(['main', 'g:Коллекции', 'g:Аналитика', 'g:Тест', 'g:Разработчику', 'settings'], \array_column($nav, 'key'), 'groups by their order: 0, 50 (default), 60, 77, 850, 900');
        self::assertSame(['today', 'pages', 'pages:blog_article', 'pages:test_tpl', 'requests', 'media'], \array_column($nav[0]['items'], 'key'), 'core items are items with an order; the quick links of templates follow «Страницы»');
        self::assertSame(['', '', ''], [$nav[0]['group'], $nav[5]['group'], $nav[0]['items'][0]['icon'] ? '' : 'x']);
        $test = $nav[3];
        self::assertSame(['Тест', 'box', 77], [$test['group'], $test['icon'], $test['order']]);
        self::assertSame([['key' => 'col:test_trips', 'label' => 'Тестовые поездки', 'to' => '/collections/test_trips', 'icon' => 'map', 'order' => 5]], $test['items']);
        self::assertSame(['/x/reports', '/x/secret'], \array_column($nav[2]['items'], 'to'));
        self::assertSame('/settings', $nav[5]['items'][0]['to']);
        self::assertContains('test_trips', \array_column($schema['collections'], 'key'));
        self::assertContains('permissionAreas', \array_keys($schema), 'the old keys stay');
        self::assertContains('action:test_trips:secretive', \array_column($schema['permissionAreas'], 'key'));
    }

    #[Test]
    public function aPersonSeesOnlyHisPartOfTheMenuAndTheSchema(): void
    {
        $limited = self::getContainer()->get(UserManager::class)->create('test-limited@example.com', 'Узкий', 'requests', 'password-for-test');
        $this->signIn($limited);
        $schema = $this->api('GET', 'schema');

        self::assertSame(['main', 'g:Аналитика'], \array_column($schema['nav'], 'key'));
        self::assertSame(['today', 'requests'], \array_column($schema['nav'][0]['items'], 'key'));
        self::assertSame(['/x/reports'], \array_column($schema['nav'][1]['items'], 'to'), 'the report that asks for «set:users» is not shown to him');
    }

    #[Test]
    public function theListKeepsItsOldAnswerWithoutParametersAndAnswersAnEnvelopeWithThem(): void
    {
        $collections = self::getContainer()->get(CollectionService::class);
        foreach ([['А', 3], ['Б', 1], ['В', 2]] as [$t, $n]) {
            $collections->create('test_trips', ['title' => $t, 'seats' => $n, 'state' => 'open']);
        }

        $old = $this->api('GET', 'collections/test_trips');
        self::assertTrue(\array_is_list($old));
        self::assertCount(3, $old);

        $new = $this->api('GET', 'collections/test_trips?sort=-seats&perPage=2&filter[state]=open');
        self::assertSame(['А', 'В'], \array_column($new['items'], 'title'));
        self::assertSame([3, 1, 2], [$new['total'], $new['page'], $new['perPage']]);
        self::assertSame(['open' => 3], $new['facets']['state']);
        self::assertSame(3, $new['metrics'][0]['value']);

        $page2 = $this->api('GET', 'collections/test_trips?sort=-seats&perPage=2&page=2');
        self::assertSame(['Б'], \array_column($page2['items'], 'title'));

        $this->api('GET', 'collections/test_trips?filter[nope]=1', [], 422);
        $this->api('GET', 'collections/test_trips?sort=note', [], 422);
    }

    #[Test]
    public function requestPhoneSearchIgnoresFormattingAndPreservesTheDisplayValue(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $form = 'test_phone_lookup';
        $formatted = new Request($form);
        $formatted->phone = "+7 (900)\u{00A0}111-22-33 доб. 42";
        $plain = new Request($form);
        $plain->phone = '79001112233';
        $noPhone = new Request($form);
        $noPhone->name = 'Редкое Имя';
        foreach ([$formatted, $plain, $noPhone] as $request) {
            $em->persist($request);
        }
        $em->flush();
        $search = fn (string $q): array => $this->api('GET', 'requests?' . \http_build_query(['form' => $form, 'q' => $q]));

        try {
            foreach (['9001112233', '+7 (900) 111-22-33', '111-22'] as $query) {
                $rows = $search($query);
                self::assertEqualsCanonicalizing([$formatted->id, $plain->id], \array_column($rows, 'id'));
                self::assertSame($formatted->phone, \array_column($rows, 'phone', 'id')[$formatted->id]);
            }
            self::assertSame([$formatted->id], \array_column($search('22-33 доб. 42'), 'id'));
            self::assertSame([$noPhone->id], \array_column($search('рЕДкОе'), 'id'));
        } finally {
            $em->createQuery('DELETE FROM ' . Request::class . ' r WHERE r.form = :form')->setParameter('form', $form)->execute();
        }
    }

    #[Test]
    public function requestDetailsKeepTheSubmittedFieldsWhenStaffUpdateTheirNote(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $worker = self::getContainer()->get(UserManager::class)->create('test-drawer@example.com', 'Заявки', 'requests', 'password-for-test');
        $this->signIn($worker);
        $form = \array_column($this->api('GET', 'schema')['forms'], null, 'key')['maintenance'];
        $itemsField = \array_column($form['fields'], null, 'k')['items'];
        self::assertSame('Заказанные работы', $itemsField['label']);
        self::assertSame('textarea', $itemsField['t']);

        $request = new Request('maintenance');
        $request->name = 'Марина';
        $data = ['name' => $request->name, 'items' => "Замена фильтра × 2\nПроверка <b>крепления</b> × 1"];
        $request->data = $data;
        $em->persist($request);
        $em->flush();
        $id = $request->id;
        $path = 'requests/' . $id;

        try {
            $em->clear();
            $detail = $this->api('GET', $path);
            self::assertSame($form['key'], $detail['form']);
            self::assertSame($form['title'], $detail['place']);
            self::assertSame($data['name'], $detail['name']);
            self::assertEquals($data, $detail['data']);
            self::assertSame('', $detail['note']);
            self::assertSame('', $detail['comment']);

            $updated = $this->api('PATCH', $path, ['work' => 'progress', 'comment' => 'Мастер подтвердил']);
            $em->clear();
            $reloaded = $this->api('GET', $path);
            foreach ([$updated, $reloaded] as $item) {
                self::assertSame('progress', $item['work']);
                self::assertSame('Мастер подтвердил', $item['comment']);
                self::assertSame('', $item['note']);
                self::assertSame($data['name'], $item['name']);
                self::assertEquals($data, $item['data']);
            }
        } finally {
            $em->createQuery('DELETE FROM ' . Request::class . ' r WHERE r.id = :id')->setParameter('id', $id)->execute();
        }
    }

    #[Test]
    public function readingAndUpdatingRequestDetailsRequireTheirOwnPermissions(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $request = new Request('maintenance');
        $request->data = ['items' => 'Масляный фильтр'];
        $em->persist($request);
        $em->flush();
        $id = $request->id;
        $path = 'requests/' . $id;

        try {
            $this->client->getCookieJar()->clear();
            foreach (['GET', 'PATCH'] as $method) {
                $denied = $this->api($method, $path, 'PATCH' === $method ? ['work' => 'closed'] : [], 401);
                self::assertArrayNotHasKey('data', $denied);
            }

            $users = self::getContainer()->get(UserManager::class);
            $this->signIn($users->create('test-drawer-author@example.com', 'Автор', 'author', 'password-for-test'));
            $denied = $this->api('GET', $path, [], 403);
            self::assertArrayNotHasKey('data', $denied);
            $this->api('PATCH', $path, ['work' => 'closed'], 403);

            $this->signIn($users->create('test-drawer-editor@example.com', 'Редактор', 'editor', 'password-for-test'));
            $this->api('PATCH', $path, ['work' => 'closed'], 403);
            $em->clear();
            $detail = $this->api('GET', $path);
            self::assertSame('new', $detail['work']);
            self::assertSame('Масляный фильтр', $detail['data']['items']);
        } finally {
            $em->createQuery('DELETE FROM ' . Request::class . ' r WHERE r.id = :id')->setParameter('id', $id)->execute();
        }
    }

    #[Test]
    public function anActionRunsOverHttp(): void
    {
        $item = self::getContainer()->get(CollectionService::class)->create('test_trips', ['title' => 'А']);

        $r = $this->api('POST', 'resources/test_trips/actions/tag', ['ids' => [$item['id']], 'input' => ['label' => 'Хит']]);
        self::assertSame(['ok' => true, 'message' => '1: Хит x-', 'reload' => true], $r);

        $this->api('POST', 'resources/test_trips/actions/tag', ['ids' => [$item['id']], 'input' => []], 422);
        $this->api('POST', 'resources/test_trips/actions/hidden', [], 403);
        $this->api('POST', 'resources/test_trips/actions/nope', [], 404);

        $error = $this->api('POST', 'resources/test_trips/actions/fails', [], 422);
        self::assertSame(['ok' => false, 'error' => 'Нечего делать', 'message' => 'Нечего делать'], $error);

        $go = $this->api('POST', 'resources/test_trips/actions/go');
        self::assertSame('/pages', $go['redirect']);

        $del = $this->api('POST', 'resources/test_trips/actions/delete-selected', ['ids' => [$item['id']]]);
        self::assertSame('Удалено записей: 1', $del['message']);
    }

    #[Test]
    public function meHandsTheSameCsrfTokenAsTheShellToTheDevServer(): void
    {
        $me = $this->api('GET', 'me');
        self::assertSame($this->csrf, $me['csrf']);
        self::assertSame('Тест API', $me['name']);
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private') || $this->client->getResponse()->headers->hasCacheControlDirective('no-store'), 'the token is not for a shared cache');

        // the token of /me is the one the admin API accepts
        $this->csrf = $me['csrf'];
        $this->api('POST', 'resources/test_trips/actions/go', [], 200);

        // a stranger gets neither the token nor the person
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/api/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 302]);
        self::assertStringNotContainsString('csrf', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anActionNeedsTheCsrfToken(): void
    {
        $this->csrf = 'wrong';
        $this->api('POST', 'resources/test_trips/actions/go', [], 403);
    }

    #[Test]
    public function aRedirectToAnUnsafeAddressIsRefused(): void
    {
        $this->client->request('POST', '/admin/api/resources/test_trips/actions/jump', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], '{}');
        self::assertSame(500, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('javascript', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function aFileMadeByAnActionIsHandedOutOnce(): void
    {
        $collections = self::getContainer()->get(CollectionService::class);
        $a = $collections->create('test_trips', ['title' => 'Алтай']);

        $r = $this->api('POST', 'resources/test_trips/actions/export', ['ids' => [$a['id']]]);
        self::assertSame('Готово', $r['message']);
        self::assertMatchesRegularExpression('~^/admin/api/downloads/[a-f0-9]{40}$~', $r['download']);

        $this->client->request('GET', $r['download']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename=trips.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $file = $this->client->getResponse()->getFile()->getPathname();
        self::assertSame("title\nАлтай", $this->client->getInternalResponse()->getContent());
        self::assertFileDoesNotExist($file, 'delete: true removes the file after sending');

        $this->client->request('GET', $r['download']);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aDownloadBelongsToThePersonWhoAskedForIt(): void
    {
        $r = $this->api('POST', 'resources/test_trips/actions/export', ['ids' => []]);
        $other = self::getContainer()->get(UserManager::class)->create('test-other@example.com', 'Другой', 'admin', 'password-for-test');
        $this->signIn($other);
        $this->client->request('GET', $r['download']);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function thePrefsFollowThePersonAndAreHisOwn(): void
    {
        self::assertSame(['key' => 'list.test_trips', 'value' => null], $this->api('GET', 'prefs/list.test_trips'));

        // jsonb keeps the keys of a JSON object in its own order, so the value is compared as a set
        $value = ['filter' => ['state' => 'open'], 'sort' => '-seats', 'view' => 'cards'];
        self::assertSame(['key' => 'list.test_trips', 'value' => $value], $this->api('PUT', 'prefs/list.test_trips', ['value' => $value]));
        self::assertEqualsCanonicalizing($value, $this->api('GET', 'prefs/list.test_trips')['value']);

        $this->api('PUT', 'prefs/list.test_trips', ['value' => ['view' => 'table']]);
        self::assertSame(['view' => 'table'], $this->api('GET', 'prefs/list.test_trips')['value'], 'a second save replaces the value');

        $other = self::getContainer()->get(UserManager::class)->create('test-pref2@example.com', 'Второй', 'editor', 'password-for-test');
        $this->signIn($other);
        self::assertNull($this->api('GET', 'prefs/list.test_trips')['value'], 'nobody reads the prefs of another person');

        $this->signIn($this->user);
        self::assertSame(['ok' => true], $this->api('DELETE', 'prefs/list.test_trips'));
        self::assertNull($this->api('GET', 'prefs/list.test_trips')['value']);
    }

    #[Test]
    public function prefsCheckTheirInput(): void
    {
        $this->api('GET', 'prefs/bad key', [], 422);
        $this->api('PUT', 'prefs/ok', [], 422);
        $this->api('PUT', 'prefs/ok', ['value' => \str_repeat('x', 20000)], 422);
        $this->client->request('GET', '/admin/api/prefs/..%2Fetc');
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 422]);
    }

    #[Test]
    public function prefsDieWithTheirUser(): void
    {
        $this->api('PUT', 'prefs/a', ['value' => 1]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_user_pref WHERE user_id = ?', [$this->user->id]);
        self::assertSame(1, $before);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id = :id')->setParameter('id', $this->user->id)->execute();
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_user_pref WHERE user_id = ?', [$this->user->id]));
    }

    #[Test]
    public function thePrefsNeedASignedInPerson(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/admin/api/prefs/a');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    #[Test]
    public function theShellLoadsThePluginsOfExtensionsAfterTheBundle(): void
    {
        $this->client->request('GET', '/admin');
        $html = (string) $this->client->getResponse()->getContent();

        $stub = \strpos($html, 'window.ITK = window.ITK ||');
        $bundle = \strpos($html, '<script type="module" src="/bundles/itkcms/admin/');
        $plugin = \strpos($html, '<script type="module" src="/bundles/test/admin.js">');
        self::assertNotFalse($stub);
        self::assertNotFalse($bundle);
        self::assertNotFalse($plugin);
        self::assertTrue($stub < $bundle && $bundle < $plugin, 'the registerPlugin stub, then the admin, then the plugins');
        self::assertStringContainsString('<link rel="stylesheet" href="/bundles/test/admin.css">', $html);
        self::assertStringNotContainsString('javascript:alert', $html);
        self::assertStringNotContainsString('evil.example', $html);
    }
}

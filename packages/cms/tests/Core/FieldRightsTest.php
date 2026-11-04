<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\GuardSection;
use Itk\Cms\Tests\Core\Fixture\GuardSettings;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Права на поле (canSee, readonly) на сервере: настройки и поля секций, как у коллекций и шаблонов;
 * вход в историю, список коллекций в схеме и лимит личных настроек.
 */
final class FieldRightsTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    private SettingsService $settings;
    private PageService $pages;
    /** @var list<string> */
    private array $pageIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $registry->add(new GuardSettings());
        $registry->add(new GuardSection());
        $registry->add(new TripsCollection());
        $this->settings = self::getContainer()->get(SettingsService::class);
        $this->pages = self::getContainer()->get(PageService::class);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        // a role that may work with the test settings and pages, but has no «set:users» and no right to the test collection
        $role = new Role('test-guard', 'Test узкая');
        $role->perms = ['set:test_guard' => ['view', 'edit'], 'pages' => ['view', 'edit']];
        $em->persist($role);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key LIKE :k')->setParameter('k', 'test%')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id IN (:ids)')->setParameter('ids', [] === $this->pageIds ? ['-'] : $this->pageIds)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k OR r.resourceId IN (:ids)')
            ->setParameter('k', 'test%')->setParameter('ids', [] === $this->pageIds ? ['-'] : $this->pageIds)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\UserPref p')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key = :k')->setParameter('k', 'test-guard')->execute();
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        /** @var User $user */
        $user = self::getContainer()->get(UserManager::class)->create('test-' . $role . '@example.com', 'Тест ' . $role, $role, 'password-for-test');
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

    private function seedSettings(): void
    {
        $this->settings->set('testShown', 'open');
        $this->settings->set('testSecret', 'top');
        $this->settings->set('testLocked', 'fixed');
    }

    #[Test]
    public function settingsHandOutOnlyWhatThePersonMaySeeAndTakeOnlyWhatHeMayWrite(): void
    {
        $this->seedSettings();

        $this->signIn('admin');
        $all = $this->api('GET', 'settings');
        self::assertSame(['open', 'top', 'fixed'], [$all['testShown'], $all['testSecret'], $all['testLocked']], 'the administrator sees all three');

        $this->signIn('test-guard');
        $got = $this->api('GET', 'settings');
        self::assertSame(['open', 'fixed'], [$got['testShown'], $got['testLocked']]);
        self::assertArrayNotHasKey('testSecret', $got, 'canSee: the value is not handed out');

        $patched = $this->api('PATCH', 'settings', ['testShown' => 'new', 'testSecret' => 'hacked', 'testLocked' => 'hacked']);
        self::assertSame('new', $patched['testShown']);
        self::assertArrayNotHasKey('testSecret', $patched);
        self::assertSame(['new', 'top', 'fixed'], [$this->settings->get('testShown'), $this->settings->get('testSecret'), $this->settings->get('testLocked')], 'the hidden and the readonly field kept their values');

        $this->api('PATCH', 'settings', ['testSecret' => 'hacked'], 200);
        self::assertSame('top', $this->settings->get('testSecret'), 'a request that carries only a hidden key saves nothing');
    }

    #[Test]
    public function aGroupThePersonMayNotViewIsNotHandedOutAtAll(): void
    {
        $this->seedSettings();
        $this->signIn('editor');

        $got = $this->api('GET', 'settings');
        self::assertArrayNotHasKey('testShown', $got);
        self::assertArrayNotHasKey('testSecret', $got);
        self::assertArrayNotHasKey('testLocked', $got);
        $this->api('PATCH', 'settings', ['testShown' => 'hacked'], 403);
        self::assertSame('open', $this->settings->get('testShown'));
    }

    #[Test]
    public function theSystemMayWriteAReadonlySetting(): void
    {
        $this->seedSettings();
        self::getContainer()->get('security.token_storage')->setToken(null);

        $this->settings->update(['testLocked' => 'by-code']);
        self::assertSame('by-code', $this->settings->get('testLocked'));
    }

    #[Test]
    public function theHistoryOfSettingsLeavesOutAHiddenField(): void
    {
        $this->signIn('admin');
        $this->api('PATCH', 'settings', ['testShown' => 'a', 'testSecret' => 'b']);

        $admin = $this->api('GET', 'history/settings/test_guard');
        $keys = static fn (array $history): array => \array_values(\array_filter(\array_map(static fn (array $row): string => (string) ($row['key'] ?? ''), \array_merge(...\array_column($history['items'], 'summary')))));
        self::assertContains('testSecret', $keys($admin), 'the administrator sees the change of the key');

        $this->signIn('test-guard');
        $limited = $this->api('GET', 'history/settings/test_guard');
        self::assertContains('testShown', $keys($limited));
        self::assertNotContains('testSecret', $keys($limited), 'the key is not in the summary');
        $entry = $this->api('GET', 'history/revision/' . $limited['items'][0]['id']);
        self::assertNotContains('testSecret', $keys(['items' => [['summary' => $entry['against']]]]), 'nor in what returning to the entry would change');
    }

    /**
     * @return array{0: string, 1: list<string>} the page id; its section ids
     */
    private function pageWithGuardSection(): array
    {
        self::getContainer()->get('security.token_storage')->setToken(null); // the system may store a readonly value
        $page = $this->pages->create([
            'template' => 'default', 'title' => 'Test секции', 'url' => '/test-guard-sections',
            'sections' => [['id' => 's-one', 'type' => 'test_guard_sec', 'data' => ['shown' => 'a', 'secret' => 'b', 'locked' => 'c']]],
        ]);
        $this->pageIds[] = $page['id'];

        return [$page['id'], ['s-one']];
    }

    /** @return array<string, mixed> the data of the section as the system reads it */
    private function stored(string $pageId, string $sectionId): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->find(\Itk\Cms\Entity\Page::class, $pageId)->translation('ru')->draft['sections'] as $s) {
            if ($s['id'] === $sectionId) {
                return (array) $s['data'];
            }
        }

        return [];
    }

    #[Test]
    public function aSectionFieldThePersonMayNotSeeOrChangeStaysAsItWas(): void
    {
        [$id] = $this->pageWithGuardSection();

        $this->signIn('test-guard');
        $page = $this->api('GET', 'pages/' . $id);
        $data = (array) $page['sections'][0]['data'];
        self::assertSame(['a', 'c'], [$data['shown'], $data['locked']]);
        self::assertArrayNotHasKey('secret', $data, 'canSee: not handed out');
        $draft = $this->api('GET', 'pages/' . $id . '/draft');
        self::assertArrayNotHasKey('secret', (array) $draft['page']['sections'][0]['data']);

        $saved = $this->api('PUT', 'pages/' . $id . '/draft', ['sections' => [
            ['id' => 's-one', 'type' => 'test_guard_sec', 'data' => ['shown' => 'a2', 'secret' => 'hacked', 'locked' => 'hacked']],
            ['id' => 's-two', 'type' => 'test_guard_sec', 'data' => ['shown' => 'x', 'secret' => 'hacked', 'locked' => 'hacked']],
        ]]);
        self::assertSame('a2', $saved['sections'][0]['data']['shown']);
        self::assertEquals(['shown' => 'a2', 'secret' => 'b', 'locked' => 'c'], $this->stored($id, 's-one'), 'the open field changed; the hidden and the readonly one kept their stored values');
        $second = $this->stored($id, 's-two');
        self::assertSame('x', $second['shown']);
        self::assertSame(['', ''], [$second['secret'] ?? '', $second['locked'] ?? ''], 'a new section gets nothing for them');
    }

    #[Test]
    public function aSectionChangeOfAHiddenFieldIsNotShownInTheListOfChanges(): void
    {
        [$id] = $this->pageWithGuardSection();
        $this->signIn('admin');
        $this->api('POST', 'pages/' . $id . '/publish');
        $this->api('PUT', 'pages/' . $id . '/draft', ['sections' => [['id' => 's-one', 'type' => 'test_guard_sec', 'data' => ['shown' => 'a2', 'secret' => 'b2', 'locked' => 'c']]]]);
        $keys = static fn (array $draft): array => \array_values(\array_filter(\array_map(static fn (array $c): string => (string) ($c['key'] ?? ''), $draft['changes'])));
        self::assertEqualsCanonicalizing(['shown', 'secret'], $keys($this->api('GET', 'pages/' . $id . '/draft')));

        $this->signIn('test-guard');
        self::assertSame(['shown'], $keys($this->api('GET', 'pages/' . $id . '/draft')), 'the change of the key is not listed for him');
    }

    #[Test]
    public function theSchemaDoesNotListACollectionThePersonHasNoRightTo(): void
    {
        $this->signIn('admin');
        self::assertContains('test_trips', \array_column($this->api('GET', 'schema')['collections'], 'key'));

        $this->signIn('test-guard');
        self::assertNotContains('test_trips', \array_column($this->api('GET', 'schema')['collections'], 'key'));
    }

    #[Test]
    public function aPersonKeepsAtMostTwoHundredPrefs(): void
    {
        $this->signIn('admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'test-admin@example.com']);
        for ($i = 0; $i < 200; ++$i) {
            $em->persist(new \Itk\Cms\Entity\UserPref($user, 'test.k' . $i, ['n' => $i]));
        }
        $em->flush();

        $this->api('PUT', 'prefs/test.over', ['value' => 1], 422);
        $this->api('PUT', 'prefs/test.k7', ['value' => ['n' => 'changed']]);
        self::assertSame(['n' => 'changed'], $this->api('GET', 'prefs/test.k7')['value'], 'an existing one can still change');
        $this->api('DELETE', 'prefs/test.k0');
        $this->api('PUT', 'prefs/test.over', ['value' => 1]);
    }
}

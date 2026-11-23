<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\Role;
use Itk\Cms\Event\RecordCreated;
use Itk\Cms\Event\RecordCreating;
use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\Field\Text;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/** Translation writes through the public admin API; each fixture owns its rows. */
final class PageTranslationsApiTest extends WebTestCase
{
    use IndexNowSettings;

    private KernelBrowser $client;
    private string $csrf = '';
    /** @var list<string> */
    private array $ids = [];
    /** @var list<string> */
    private array $users = [];
    /** @var list<string> */
    private array $roles = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        $itk = ['main_locale' => 'ru'];
        if ($options['contentLocales'] ?? true) {
            $itk['locales'] = ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']]; // i18n-ignore: configured language names
        }

        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, framework: ['http_client' => ['enabled' => true]], itk: $itk);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new class extends TemplateResource {
            public function key(): string
            {
                return 'test_translation';
            }

            public function title(): string
            {
                return 'Translation fields';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title');
                yield Text::make('Subtitle', 'subtitle')->initial('Schema subtitle');
                yield Text::make('Secret', 'secret')->initial('Schema private')->canSee('set:users');
                yield Text::make('Locked', 'locked')->initial('Schema locked')->readonly();
            }
        });
        $this->login('admin');
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach (\array_reverse($this->ids) as $id) {
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id = :id')->setParameter('id', $id)->execute();
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', $this->ids ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id IN (:ids)')->setParameter('ids', $this->users ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key IN (:keys)')->setParameter('keys', $this->roles ?: ['-'])->execute();
        parent::tearDown();
    }

    private function login(string $role): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-page-translations-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Translations', $role, 'password-for-test');
        $this->users[] = $user->id;
        $this->client->loginUser($user, 'itk_admin');
        $this->csrf = (string) $this->api('GET', 'me')['csrf'];
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body, \JSON_THROW_ON_ERROR));
        $text = (string) $this->client->getResponse()->getContent();
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $text);

        return (array) \json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function page(array $data = []): array
    {
        $page = $this->api('POST', 'pages', $data + ['title' => 'Translation source', 'url' => '/test-translations-' . \bin2hex(\random_bytes(6))], 201);
        $this->ids[] = $page['id'];

        return $page;
    }

    /** @return array<string, mixed> */
    private function add(string $id, string $locale, ?string $from = 'ru'): array
    {
        return $this->api('POST', 'pages/' . $id . '/translations?locale=' . $locale, ['from' => $from], 201);
    }

    #[Test]
    public function copyingDraftIntoFourLanguagesPreservesSectionsAndResetsWorkflow(): void
    {
        $source = $this->page(['seo' => ['title' => 'SEO source', 'description' => 'Description'], 'sections' => [
            ['id' => 'source-section', 'type' => 'hero', 'hidden' => false, 'data' => ['headline' => 'Draft headline']],
        ]]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $ru = $em->find(Page::class, $source['id'])->translation('ru');
        $ru->awaiting = ['by' => 'Author', 'at' => '2026-11-24'];
        $ru->returned = ['by' => 'Editor', 'at' => '2026-11-24'];
        $ru->migrated = true;
        $ru->published = $ru->draft;
        $ru->publishedUrl = $ru->draft['url'];
        $ru->status = PageTranslation::PUBLISHED;
        $ru->publishedAt = new \DateTimeImmutable();
        $em->flush();
        foreach (['kk', 'tr', 'zh', 'en'] as $locale) {
            $copy = $this->add($source['id'], $locale);
            self::assertSame($source['id'], $copy['id']);
            self::assertSame($locale, $copy['locale']);
            self::assertSame('/' . $locale . $source['url'], $copy['url']);
            self::assertEquals($source['sections'], $copy['sections']);
            self::assertEquals($source['seo'], $copy['seo']);
            self::assertSame('draft', $copy['status']);
            self::assertFalse($copy['migrated']);
            $stored = self::getContainer()->get(PageService::class)->find($source['id'])->translation($locale);
            self::assertNull($stored->published);
            self::assertNull($stored->publishedUrl);
            self::assertNull($stored->publishedAt);
            self::assertNull($stored->awaiting);
            self::assertNull($stored->returned);
            self::assertSame(Revision::CREATE, self::getContainer()->get(RevisionRepository::class)->latest(Revision::PAGE, $source['id'], $locale)->action);
        }
        $this->api('PUT', 'pages/' . $source['id'] . '/draft?locale=kk', ['title' => 'Changed only KK']);
        self::assertSame($source['title'], $this->api('GET', 'pages/' . $source['id'] . '/draft?locale=ru')['page']['title']);
        self::assertSame($source['title'], $this->api('GET', 'pages/' . $source['id'] . '/draft?locale=en')['page']['title']);
    }

    #[Test]
    public function nullSourceUsesSchemaDefaultsAndMainTitleAndAddress(): void
    {
        $source = $this->page(['seo' => ['description' => 'Do not copy'], 'sections' => [['id' => 'old', 'type' => 'text', 'data' => ['text' => 'Do not copy']]]]);
        $copy = $this->add($source['id'], 'kk', null);
        self::assertSame($source['title'], $copy['title']);
        self::assertSame('/kk' . $source['url'], $copy['url']);
        self::assertSame(['title' => '', 'description' => ''], $copy['seo']);
        self::assertCount(1, $copy['sections']);
        self::assertSame('hero', $copy['sections'][0]['type']);
        self::assertNotSame('old', $copy['sections'][0]['id']);
        self::assertSame($source['title'], $copy['sections'][0]['data']['headline']);
    }

    #[Test]
    public function copyRespectsTemplateAndSectionFieldPermissionsAndNullUsesFieldDefaults(): void
    {
        $source = $this->page(['template' => 'test_translation', 'fields' => ['subtitle' => 'Source subtitle', 'secret' => 'Private source'], 'sections' => [
            ['id' => 'permission-section', 'type' => 'notes', 'data' => ['summary' => 'Visible', 'internal' => 'Private section']],
        ]]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(Page::class, $source['id'])->translation('ru')->draft['fields']['locked'] = 'Stored locked value';
        $role = new Role('test-translation-editor-' . \bin2hex(\random_bytes(6)), 'Translation editor');
        $role->perms = ['pages' => ['edit']];
        $role->templates = ['test_translation'];
        $this->roles[] = $role->key;
        $em->persist($role);
        $em->flush();
        $this->login($role->key);
        $hookRan = false;
        $listener = static function (RecordCreating $event) use (&$hookRan): void {
            if ('test_translation' !== $event->resourceKey || !$event->translationOnly || 'kk' !== $event->locale) {
                return;
            }
            $hookRan = true;
            $event->data['fields']['secret'] = 'Private hook value';
            $event->data['fields']['locked'] = 'Locked hook value';
            $event->data['sections'][0]['data']['internal'] = 'Private section hook value';
        };
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(RecordCreating::class, $listener);
        try {
            $copy = $this->add($source['id'], 'kk');
        } finally {
            $dispatcher->removeListener(RecordCreating::class, $listener);
        }
        self::assertTrue($hookRan);
        self::assertSame('Source subtitle', $copy['fields']['subtitle']);
        self::assertSame('permission-section', $copy['sections'][0]['id']);
        self::assertSame('Visible', $copy['sections'][0]['data']['summary']);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $stored = self::getContainer()->get(PageService::class)->find($source['id'])->translation('kk')->draft;
        self::assertArrayNotHasKey('secret', $stored['fields']);
        self::assertArrayNotHasKey('locked', $stored['fields']);
        self::assertArrayNotHasKey('internal', $stored['sections'][0]['data']);
        $blank = $this->add($source['id'], 'en', null);
        self::assertSame('Schema subtitle', $blank['fields']['subtitle']);
        self::assertSame($source['title'], $blank['fields']['title']);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $storedBlank = self::getContainer()->get(PageService::class)->find($source['id'])->translation('en')->draft;
        self::assertSame('Schema private', $storedBlank['fields']['secret']);
        self::assertSame('Schema locked', $storedBlank['fields']['locked']);
    }

    #[Test]
    public function invalidLanguagesAndSourcesCannotCreateAVersion(): void
    {
        $source = $this->page();
        $path = 'pages/' . $source['id'] . '/translations';
        foreach ([['kk', 'kk'], ['kk', 'zz'], ['zz', 'ru']] as [$locale, $from]) {
            $this->api('POST', $path . '?locale=' . $locale, ['from' => $from], 422);
        }
        foreach (['', 42, [], true] as $invalidFrom) {
            $this->api('POST', $path . '?locale=kk', ['from' => $invalidFrom], 422);
        }
        $missing = $this->api('POST', $path . '?locale=kk', ['from' => 'en'], 422);
        self::assertSame('translation_source_missing', $missing['details']['code']);
        $this->add($source['id'], 'kk');
        $duplicate = $this->api('POST', $path . '?locale=kk', ['from' => 'ru'], 409);
        self::assertSame('translation_exists', $duplicate['details']['code']);
        self::assertStringContainsString('Қазақша', $duplicate['error']); // i18n-ignore: configured language name
        self::assertNull(self::getContainer()->get(PageService::class)->find($source['id'])->translation('zz'));
    }

    #[Test]
    public function occupiedDraftAndPublishedAddressesGetTheNextFreeSuffix(): void
    {
        $source = $this->page();
        $other = $this->page();
        $this->add($other['id'], 'kk');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $translation = $em->find(Page::class, $other['id'])->translation('kk');
        $translation->draft['url'] = '/kk' . $source['url'];
        $translation->publishedUrl = '/kk' . $source['url'] . '-2';
        $translation->published = $translation->draft;
        $translation->published['url'] = $translation->publishedUrl;
        $translation->status = PageTranslation::PUBLISHED;
        $em->flush();
        self::assertSame('/kk' . $source['url'] . '-3', $this->add($source['id'], 'kk')['url']);
    }

    #[Test]
    public function prefixesAreReplacedOnlyWhenActuallyPresentIncludingLanguageHomes(): void
    {
        foreach ([['/', 'ru', '/kk'], ['/en', 'en', '/kk'], ['/en/about', 'en', '/kk/about'], ['/en/about/', 'en', '/kk/about/'], ['/legacy', 'en', '/kk/legacy'], ['/tr/legacy', 'en', '/kk/tr/legacy']] as [$url, $from, $expected]) {
            $source = $this->page();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $page = $em->find(Page::class, $source['id']);
            $t = $page->translation($from) ?? new PageTranslation($page, $from);
            $t->draft = $page->translation('ru')->draft;
            $t->draft['url'] = $url;
            $page->translations->set($from, $t);
            $em->persist($t);
            $em->flush();
            self::assertSame($expected, $this->add($source['id'], 'kk', $from)['url']);
            $this->api('DELETE', 'pages/' . $source['id'] . '/translations/kk');
        }
    }

    #[Test]
    public function nullSourceFallsBackToTheFirstExistingLanguage(): void
    {
        $source = $this->page();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->find(Page::class, $source['id']);
        $translation = $page->translation('ru');
        $page->translations->remove('ru');
        $translation->locale = 'en';
        $translation->draft['url'] = '/en' . $source['url'];
        $page->translations->set('en', $translation);
        $em->flush();
        $copy = $this->add($source['id'], 'kk', null);
        self::assertSame($source['title'], $copy['title']);
        self::assertSame('/kk' . $source['url'], $copy['url']);
    }

    #[Test]
    public function aPreviouslyLoadedCollectionIsRefreshedBeforeAddingAnotherLanguage(): void
    {
        $source = $this->page();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PageService::class);
        self::assertCount(1, $service->find($source['id'])->translations);
        // Represent a second writer which finished after the ORM collection was loaded.
        $em->getConnection()->executeStatement(
            "INSERT INTO itk_page_translation (id, page_id, locale, draft, status, updated_at, migrated) SELECT :id, page_id, 'en', draft, 'draft', updated_at, false FROM itk_page_translation WHERE page_id = :page AND locale = 'ru'",
            ['id' => Uuid::v7()->toRfc4122(), 'page' => $source['id']],
        );
        $service->addTranslation($source['id'], 'kk', 'ru');
        $em->clear();
        self::assertCount(3, $service->find($source['id'])->translations);
        self::assertNotNull($service->find($source['id'])->translation('en'));
        self::assertNotNull($service->find($source['id'])->translation('kk'));
    }

    #[Test]
    public function legacyConfigurationAcceptsHistoricalLanguagesAndUnprefixedDrafts(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient(['contentLocales' => false]);
        $this->client->disableReboot();
        $this->login('admin');
        $source = $this->page();
        self::assertSame('/en' . $source['url'], $this->add($source['id'], 'en')['url']);
        self::assertSame('/tr' . $source['url'], $this->add($source['id'], 'tr', 'en')['url']);
        $this->api('PUT', 'pages/' . $source['id'] . '/draft?locale=en', ['url' => '/EN' . $source['url']]);
        self::assertSame('/kk' . $source['url'], $this->add($source['id'], 'kk', 'en')['url']);
        $this->api('PUT', 'pages/' . $source['id'] . '/draft?locale=en', ['url' => $source['url'] . '-legacy']);
        self::assertSame('/zz' . $source['url'] . '-legacy', $this->add($source['id'], 'zz', 'en')['url']);
    }

    #[Test]
    public function anOccupiedLanguageHomeCannotReceiveASuffix(): void
    {
        $source = $this->page();
        $other = $this->page();
        $this->add($other['id'], 'kk');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(Page::class, $source['id'])->translation('ru')->draft['url'] = '/';
        $em->find(Page::class, $other['id'])->translation('kk')->draft['url'] = '/kk';
        $em->flush();
        $this->api('POST', 'pages/' . $source['id'] . '/translations?locale=kk', ['from' => 'ru'], 409);
    }

    #[Test]
    public function malformedOrReservedTranslationAddressesFailBeforeWriting(): void
    {
        $source = $this->page();
        foreach (['/path?query=1', '/path#fragment', '//host/path', '/bad//path', '/bad\\path', '/bad' . "\n" . 'path', '/' . \str_repeat('x', 252)] as $url) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->find(Page::class, $source['id'])->translation('ru')->draft['url'] = $url;
            $em->flush();
            $this->api('POST', 'pages/' . $source['id'] . '/translations?locale=kk', ['from' => 'ru'], 422);
            self::assertNull(self::getContainer()->get(PageService::class)->find($source['id'])->translation('kk'));
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->find(Page::class, $source['id']);
        $translation = $page->translation('ru');
        $page->translations->remove('ru');
        $translation->locale = 'en';
        $translation->draft['url'] = '/en/robots.txt';
        $page->translations->set('en', $translation);
        $em->flush();
        $this->api('POST', 'pages/' . $source['id'] . '/translations?locale=ru', ['from' => 'en'], 422);
        self::assertNull(self::getContainer()->get(PageService::class)->find($source['id'])->translation('ru'));
    }

    #[Test]
    public function deletionIsLocalAndPreservesTheDeletedLiveSnapshotInHistory(): void
    {
        $source = $this->page();
        $kk = $this->add($source['id'], 'kk');
        $this->api('POST', 'pages/' . $source['id'] . '/publish?locale=kk');
        $events = [];
        foreach ([RecordUnpublished::class, RecordDeleted::class] as $event) {
            self::getContainer()->get('event_dispatcher')->addListener($event, static function ($e) use (&$events): void {
                $events[] = [$e::class, $e->record->locale, $e->record->translationOnly];
            });
        }
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/kk');
        $missing = $this->api('GET', 'pages/' . $source['id'] . '/draft?locale=kk');
        self::assertFalse($missing['translated']);
        self::assertNull($missing['page']);
        self::assertSame($source['title'], $this->api('GET', 'pages/' . $source['id'] . '/draft')['page']['title']);
        $revision = self::getContainer()->get(RevisionRepository::class)->latest(Revision::PAGE, $source['id'], 'kk');
        self::assertSame(Revision::DELETE_TRANSLATION, $revision->action);
        self::assertSame($kk['url'], $revision->snapshot['draft']['url']);
        self::assertSame('published', $revision->snapshot['status']);
        self::assertSame($kk['url'], $revision->snapshot['published']['url']);
        self::assertSame([[RecordUnpublished::class, 'kk', true], [RecordDeleted::class, 'kk', true]], $events);
        foreach (self::getContainer()->get(RevisionRepository::class)->trash() as $trash) {
            self::assertNotSame($source['id'], $trash->resourceId);
        }
        $missing = $this->api('DELETE', 'pages/' . $source['id'] . '/translations/kk', status: 404);
        self::assertSame('no_translation', $missing['details']['code']);
    }

    #[Test]
    public function mainAndLastTranslationsCannotBeDeletedButAwaitingVersionsCan(): void
    {
        $source = $this->page();
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/ru', status: 409);
        $this->add($source['id'], 'kk');
        $this->api('POST', 'pages/' . $source['id'] . '/submit?locale=kk');
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/ru', status: 409);
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/kk');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->find(Page::class, $source['id']);
        $t = $page->translation('ru');
        $page->translations->remove('ru');
        $t->locale = 'en';
        $t->draft['url'] = '/en' . $source['url'];
        $page->translations->set('en', $t);
        $em->flush();
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/en', status: 409);
    }

    #[Test]
    public function secondaryHomeCanBeUnpublishedAndDeletedWithALiveChild(): void
    {
        $source = $this->page();
        $child = $this->page(['parentId' => $source['id']]);
        $this->add($source['id'], 'kk');
        $this->add($child['id'], 'kk');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(Page::class, $source['id'])->template = 'home';
        $em->find(Page::class, $source['id'])->translation('ru')->draft['url'] = '/';
        $em->find(Page::class, $source['id'])->translation('kk')->draft['url'] = '/kk';
        $em->flush();
        $this->api('POST', 'pages/' . $source['id'] . '/publish?locale=ru');
        $this->api('POST', 'pages/' . $source['id'] . '/publish?locale=kk');
        $this->api('POST', 'pages/' . $child['id'] . '/publish?locale=kk');
        $this->api('POST', 'pages/' . $source['id'] . '/unpublish?locale=ru', status: 409);
        $this->api('POST', 'pages/' . $source['id'] . '/unpublish?locale=kk');
        $this->api('POST', 'pages/' . $source['id'] . '/publish?locale=kk');
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/kk');
        self::assertSame('published', $this->api('GET', 'pages/' . $child['id'] . '/draft?locale=kk')['page']['status']);
    }

    #[Test]
    public function secondaryUnpublishAndRemovalQueueThePublishedAddressAfterCommit(): void
    {
        $this->setting('domain', 'translation-index.example.org');
        $this->setting('indexNow', true);
        $source = $this->page();
        $kk = $this->add($source['id'], 'kk');
        $this->api('POST', 'pages/' . $source['id'] . '/publish?locale=kk');
        $this->api('PUT', 'pages/' . $source['id'] . '/draft?locale=kk', ['url' => $kk['url'] . '-draft']);
        $transport = self::getContainer()->get('messenger.transport.async');
        $transport->reset();
        $this->api('POST', 'pages/' . $source['id'] . '/unpublish?locale=kk');
        $paths = [];
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof PingIndexNow) {
                $paths[] = $envelope->getMessage()->path;
            }
        }
        self::assertSame([$kk['url']], $paths);
        $this->api('POST', 'pages/' . $source['id'] . '/republish?locale=kk');
        $transport->reset();
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/kk');
        $paths = [];
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof PingIndexNow) {
                $paths[] = $envelope->getMessage()->path;
            }
        }
        self::assertSame([$kk['url']], $paths);
    }

    #[Test]
    public function translationWritesRequireAuthenticationAndTheirOwnPermissions(): void
    {
        $source = $this->page();
        $this->login('requests');
        $this->api('POST', 'pages/' . $source['id'] . '/translations?locale=kk', ['from' => 'ru'], 403);
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/ru', status: 403);
        $this->client->getCookieJar()->clear();
        self::getContainer()->get('security.token_storage')->setToken(null);
        $this->api('POST', 'pages/' . $source['id'] . '/translations?locale=kk', ['from' => 'ru'], 401);
        $this->api('DELETE', 'pages/' . $source['id'] . '/translations/ru', status: 401);
    }

    #[Test]
    public function aFailedRemovalRollsBackTheLiveStateHistoryAndDeferredHooks(): void
    {
        $source = $this->page();
        $this->add($source['id'], 'kk');
        $this->api('POST', 'pages/' . $source['id'] . '/publish?locale=kk');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $events = [];
        foreach ([RecordUnpublished::class, RecordDeleted::class] as $event) {
            self::getContainer()->get('event_dispatcher')->addListener($event, static function ($e) use (&$events): void { $events[] = $e; });
        }
        $listener = new class {
            public function preFlush(PreFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
                    if ($entity instanceof PageTranslation && 'kk' === $entity->locale) {
                        throw new \RuntimeException('Simulated deletion failure');
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::preFlush], $listener);
        try {
            self::getContainer()->get(PageService::class)->removeTranslation($source['id'], 'kk');
            self::fail('The simulated deletion must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated deletion failure', $e->getMessage());
        } finally {
            $em->getEventManager()->removeEventListener([Events::preFlush], $listener);
        }
        $em->clear();
        self::assertTrue($em->find(Page::class, $source['id'])->translation('kk')->isLive());
        self::assertSame(Revision::PUBLISH, self::getContainer()->get(RevisionRepository::class)->latest(Revision::PAGE, $source['id'], 'kk')->action);
        self::assertSame([], $events);
    }

    #[Test]
    public function aUniqueConstraintRaceIsReportedAsAConflictAndRollsBackHistoryAndHooks(): void
    {
        $source = $this->page();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $created = 0;
        self::getContainer()->get('event_dispatcher')->addListener(RecordCreated::class, static function () use (&$created): void { ++$created; });
        $listener = new class {
            public function preFlush(PreFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof PageTranslation && 'kk' === $entity->locale) {
                        $driver = new class('duplicate key violates unique constraint itk_page_translation_page_locale') extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
                            public function getSQLState(): ?string
                            {
                                return '23505';
                            }
                        };
                        throw new UniqueConstraintViolationException($driver, null);
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener([Events::preFlush], $listener);
        try {
            $error = $this->api('POST', 'pages/' . $source['id'] . '/translations?locale=kk', ['from' => 'ru'], 409);
            self::assertSame('translation_exists', $error['details']['code']);
        } finally {
            $em->getEventManager()->removeEventListener([Events::preFlush], $listener);
        }
        $em->clear();
        self::assertNull($em->find(Page::class, $source['id'])->translation('kk'));
        self::assertNull(self::getContainer()->get(RevisionRepository::class)->latest(Revision::PAGE, $source['id'], 'kk'));
        self::assertSame(0, $created);
    }
}

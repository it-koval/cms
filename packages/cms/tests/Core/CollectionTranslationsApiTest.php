<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\EditLock;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Event\RecordCreated;
use Itk\Cms\Event\RecordCreating;
use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordDeleting;
use Itk\Cms\Event\RecordTranslationAdded;
use Itk\Cms\Event\RecordTranslationRemoved;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\Event\RecordUpdating;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Textarea;
use Itk\Cms\Field\Toggle;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\Record;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Public translation routes, including JSON-column freshness under the resource lock. */
final class CollectionTranslationsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    private const COLLECTION = 'test_translation_items';

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: ['locales' => [
            'ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'],
            'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'],
            'en' => ['name' => 'English'],
        ]]);
    }

    protected function setUp(): void
    {
        TranslationItemsCollection::$lifecycle = [];
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new TranslationItemsCollection());
        $this->signIn('admin');
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', self::COLLECTION)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey = :c')->setParameter('c', self::COLLECTION)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-translation-items-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-translation-items-' . $role . '-' . \bin2hex(\random_bytes(6)) . '@example.com', $role, $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $matches);
        $this->csrf = $matches[1] ?? '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/collections/' . self::COLLECTION . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function service(): CollectionService
    {
        return self::getContainer()->get(CollectionService::class);
    }

    #[Test]
    public function copiesTheSourceDraftIntoTheSameItemAndStartsWithFreshState(): void
    {
        $parent = $this->api('POST', '', ['title' => 'Parent'], 201);
        $item = $this->api('POST', '', ['title' => 'Live', 'parentId' => $parent['id']], 201);
        $id = $item['id'];
        $this->service()->publish(self::COLLECTION, $id);
        $this->service()->update(self::COLLECTION, $id, ['title' => 'Draft']);
        $this->service()->submit(self::COLLECTION, $id);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->find(CollectionItem::class, $id);
        self::assertNotNull($entity);
        $position = $entity->position;
        $source = $entity->translations['ru'];
        $creating = [];
        self::getContainer()->get('event_dispatcher')->addListener(RecordCreating::class, static function (RecordCreating $event) use (&$creating): void {
            if (self::COLLECTION === $event->resourceKey) {
                $creating[] = $event->locale;
            }
        });
        $created = [];
        self::getContainer()->get('event_dispatcher')->addListener(RecordCreated::class, static function (RecordCreated $event) use (&$created): void {
            if (self::COLLECTION === $event->resourceKey) {
                $created[] = $event->record->locale;
            }
        });

        $added = [];
        self::getContainer()->get('event_dispatcher')->addListener(RecordTranslationAdded::class, static function (RecordTranslationAdded $event) use (&$added): void {
            if (self::COLLECTION === $event->resourceKey) {
                self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->isTransactionActive());
                $added[] = $event->record->locale;
            }
        });
        TranslationItemsCollection::$lifecycle = [];
        $translation = $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'ru'], 201);
        self::assertSame($id, $translation['id']);
        self::assertSame('kk', $translation['locale']);
        self::assertSame('Draft', $translation['title']);
        self::assertSame($parent['id'], $translation['parentId']);
        self::assertSame('draft', $translation['status']);
        self::assertNull($translation['awaiting']);
        self::assertNull($translation['returned']);
        self::assertSame([], $creating);
        self::assertSame([], $created);
        self::assertSame([], TranslationItemsCollection::$lifecycle);
        self::assertSame(['kk'], $added);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $entity = $em->find(CollectionItem::class, $id);
        self::assertNotNull($entity);
        self::assertEquals($source, $entity->translations['ru']);
        self::assertSame($position, $entity->position);
        self::assertNull($entity->translations['kk']['published']);
        self::assertArrayNotHasKey('publishedAt', $entity->translations['kk']);
        $get = $this->api('GET', '/' . $id . '?locale=kk');
        self::assertTrue($get['translated']);
        self::assertEquals($translation, $get['item']);
        self::assertNull($get['published']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $revision = $em->getRepository(Revision::class)->findOneBy(['resourceId' => $id, 'locale' => 'kk', 'action' => Revision::CREATE]);
        self::assertNotNull($revision);
        self::assertSame('Draft', $revision->snapshot['draft']['title']);
    }

    #[Test]
    public function blankTranslationsUseCreateDefaultsAndAllFiveConfiguredLanguages(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source', 'enabled' => false], 201)['id'];
        $defaults = $this->api('POST', '', [], 201);
        foreach (['kk', 'tr', 'zh', 'en'] as $locale) {
            $item = $this->api('POST', '/' . $id . '/translations?locale=' . $locale, ['from' => null], 201);
            self::assertSame($defaults['title'], $item['title']);
            self::assertTrue($item['enabled']);
            self::assertSame($locale, $item['locale']);
        }
        self::assertCount(5, $this->api('GET', '/' . $id)['locales']);
    }

    #[Test]
    public function invalidSourceTargetAndDuplicateLeaveTheItemUntouched(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source'], 201)['id'];
        $this->api('POST', '/' . $id . '/translations?locale=de', ['from' => null], 422);
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'de'], 422);
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'kk'], 422);
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'en'], 422);
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => false], 422);
        $this->api('POST', '/00000000-0000-4000-8000-000000000000/translations?locale=kk', ['from' => null], 404);
        self::assertCount(1, $this->api('GET', '/' . $id)['locales']);
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'ru'], 201);
        $error = $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => null], 409);
        self::assertStringContainsString('Қазақша', $error['error']);
    }

    #[Test]
    public function deletionRemovesOnlyItsLanguageAndRecordsItsSnapshotAfterCommit(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source'], 201)['id'];
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'ru'], 201);
        $this->service()->publish(self::COLLECTION, $id, 'kk');
        $events = [];
        foreach ([RecordDeleting::class, RecordDeleted::class, RecordUnpublished::class, RecordTranslationRemoved::class] as $class) {
            self::getContainer()->get('event_dispatcher')->addListener($class, static function ($event) use (&$events, $class): void {
                if (self::COLLECTION === $event->resourceKey) {
                    self::assertFalse(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->isTransactionActive());
                    $events[] = [$class, $event->record->locale];
                }
            });
        }
        TranslationItemsCollection::$lifecycle = [];
        self::assertSame(['ok' => true], $this->api('DELETE', '/' . $id . '/translations/kk'));
        self::assertSame([[RecordUnpublished::class, 'kk'], [RecordTranslationRemoved::class, 'kk']], $events);
        self::assertSame([], TranslationItemsCollection::$lifecycle);
        $missing = $this->api('GET', '/' . $id . '?locale=kk');
        self::assertFalse($missing['translated']);
        self::assertNull($missing['item']);
        self::assertSame('Source', $this->api('GET', '/' . $id)['item']['title']);
        self::assertSame([], $this->service()->published(self::COLLECTION, 'kk'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $revision = $em->getRepository(Revision::class)->findOneBy(['resourceId' => $id, 'locale' => 'kk', 'action' => Revision::DELETE_TRANSLATION]);
        self::assertNotNull($revision);
        self::assertSame('Source', $revision->snapshot['draft']['title']);
        $this->api('DELETE', '/' . $id . '/translations/kk', [], 404);
        $this->api('DELETE', '/' . $id . '/translations/ru', [], 409);
        $this->api('DELETE', '/' . $id . '/translations/de', [], 422);
        $this->api('PUT', '/' . $id . '?locale=kk', ['title' => 'Lost'], 404);
    }

    #[Test]
    public function lastNonMainTranslationAndSingletonAreProtected(): void
    {
        $item = $this->service()->create(self::COLLECTION, ['title' => 'Only'], 'kk');
        $this->api('DELETE', '/' . $item['id'] . '/translations/kk', [], 409);
        self::getContainer()->get(Schema::class)->collection(self::COLLECTION)->singleton($item['id']);
        $translated = $this->api('POST', '/' . $item['id'] . '/translations?locale=ru', ['from' => 'kk'], 201);
        self::assertSame($item['id'], $translated['id']);
        $this->api('DELETE', '/' . $item['id'] . '/translations/kk');
        self::assertSame($item['id'], $this->api('GET', '/' . $item['id'])['item']['id']);
    }

    #[Test]
    public function copyingHonorsHiddenAndReadonlyFieldsAndRequiresTheOperationRight(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source', 'secret' => 'Private'], 201)['id'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $em->find(CollectionItem::class, $id);
        self::assertNotNull($entity);
        $entity->translations['ru']['draft']['locked'] = 'Internal';
        $em->flush();
        $this->signIn('editor');
        $copy = $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'ru'], 201);
        self::assertArrayNotHasKey('secret', $copy);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $entity = $em->find(CollectionItem::class, $id);
        self::assertNotNull($entity);
        self::assertSame('Private', $entity->translations['ru']['draft']['secret']);
        self::assertSame('Internal', $entity->translations['ru']['draft']['locked']);
        self::assertSame('', $entity->translations['kk']['draft']['secret']);
        self::assertSame('', $entity->translations['kk']['draft']['locked']);
        $this->signIn('requests');
        $this->api('POST', '/' . $id . '/translations?locale=tr', ['from' => null], 403);
        $this->api('DELETE', '/' . $id . '/translations/kk', [], 403);
        $this->client->getCookieJar()->clear();
        self::getContainer()->get('security.token_storage')->setToken(null);
        $this->api('POST', '/' . $id . '/translations?locale=tr', ['from' => null], 401);
        $this->api('DELETE', '/' . $id . '/translations/kk', [], 401);
    }

    #[Test]
    public function refreshingAfterTheLockPreservesAnotherConnectionsLanguageAndSourceDraft(): void
    {
        $id = $this->api('POST', '', ['title' => 'Stale'], 201)['id'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $stale = $em->find(CollectionItem::class, $id);
        $fresh = $stale->translations;
        $fresh['ru']['draft']['title'] = 'Latest';
        $fresh['tr'] = $fresh['ru'];
        $other = DriverManager::getConnection($em->getConnection()->getParams());
        try {
            $other->executeStatement('UPDATE itk_collection_item SET translations = ? WHERE id = ?', [\json_encode($fresh, \JSON_THROW_ON_ERROR), $id]);
            self::assertSame('Stale', $stale->translations['ru']['draft']['title']);
            try {
                $this->service()->addTranslation(self::COLLECTION, $id, 'tr', 'ru');
                self::fail('A language inserted by another connection must not be overwritten.');
            } catch (ContentException $error) {
                self::assertSame(409, $error->status());
                self::assertStringContainsString('Türkçe', $error->getMessage());
            }
            $fresh['ru']['draft']['title'] = 'Latest source';
            $other->executeStatement('UPDATE itk_collection_item SET translations = ? WHERE id = ?', [\json_encode($fresh, \JSON_THROW_ON_ERROR), $id]);
            $copy = $this->service()->addTranslation(self::COLLECTION, $id, 'kk', 'ru');
            self::assertSame('Latest source', $copy['title']);
            self::assertArrayHasKey('tr', $stale->translations);
            $fresh = $stale->translations;
            $fresh['tr']['draft']['title'] = 'Concurrent edit';
            $other->executeStatement('UPDATE itk_collection_item SET translations = ? WHERE id = ?', [\json_encode($fresh, \JSON_THROW_ON_ERROR), $id]);
            $this->service()->removeTranslation(self::COLLECTION, $id, 'kk');
            self::assertEqualsCanonicalizing(['ru', 'tr'], \array_keys($stale->translations));
            self::assertSame('Concurrent edit', $stale->translations['tr']['draft']['title']);
        } finally {
            $other->close();
        }
    }

    #[Test]
    public function deletionValidatesLocalePathSegmentsAgainstTheConfiguredList(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source'], 201)['id'];
        foreach (['KK', 'en-US', 'not_a_locale'] as $locale) {
            $this->api('DELETE', '/' . $id . '/translations/' . $locale, [], 422);
        }
        self::assertSame('Source', $this->api('GET', '/' . $id)['item']['title']);
    }

    /** @return iterable<string, array{string}> */
    public static function translationMutations(): iterable
    {
        foreach (['update', 'updateWithVersion', 'restoreVersion', 'publish', 'publishWithVersion', 'revert', 'submit', 'returnToAuthor', 'unpublish'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[Test]
    #[DataProvider('translationMutations')]
    public function everyLanguageMutationPreservesAConcurrentlyAddedLanguage(string $mutation): void
    {
        $service = $this->service();
        $item = $service->create(self::COLLECTION, ['title' => 'Live']);
        $id = $item['id'];
        $service->publish(self::COLLECTION, $id);
        $item = $service->update(self::COLLECTION, $id, ['title' => 'Draft']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $stale = $em->find(CollectionItem::class, $id);
        self::assertNotNull($stale);
        $fresh = $stale->translations;
        $fresh['kk'] = ['draft' => ['title' => 'Concurrent Kazakh'], 'published' => null, 'status' => 'draft', 'updatedAt' => '2026-11-24T00:00:00+00:00'];
        $other = DriverManager::getConnection($em->getConnection()->getParams());
        try {
            // Write after Doctrine loaded the entity: reusing its identity-map JSON loses this key.
            $other->executeStatement('UPDATE itk_collection_item SET translations = ? WHERE id = ?', [\json_encode($fresh, \JSON_THROW_ON_ERROR), $id]);
            self::assertArrayNotHasKey('kk', $stale->translations);
            $result = match ($mutation) {
                'update' => $service->update(self::COLLECTION, $id, ['title' => 'Edited'], 'ru'),
                'updateWithVersion' => $service->update(self::COLLECTION, $id, ['title' => 'Edited'], 'ru', baseVersion: $item['version']),
                'restoreVersion' => $service->restoreVersion(self::COLLECTION, $id, ['title' => 'Restored'], 'ru'),
                'publish' => $service->publish(self::COLLECTION, $id, 'ru'),
                'publishWithVersion' => $service->publish(self::COLLECTION, $id, 'ru', $item['version']),
                'revert' => $service->revert(self::COLLECTION, $id, 'ru'),
                'submit' => $service->submit(self::COLLECTION, $id, 'ru'),
                'returnToAuthor' => $service->returnToAuthor(self::COLLECTION, $id, 'ru'),
                'unpublish' => $service->unpublish(self::COLLECTION, $id, 'ru'),
                default => throw new \LogicException('Unexpected test mutation: ' . $mutation),
            };
            self::assertSame('ru', $result['locale']);
            $stored = \json_decode($other->fetchOne('SELECT translations FROM itk_collection_item WHERE id = ?', [$id]), true, flags: \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('kk', $stored);
            self::assertEquals($fresh['kk'], $stored['kk']);
            self::assertSame(match ($mutation) {
                'update', 'updateWithVersion' => 'Edited',
                'restoreVersion' => 'Restored',
                'revert' => 'Live',
                default => 'Draft',
            }, $stored['ru']['draft']['title']);
            if ('submit' === $mutation) {
                self::assertArrayHasKey('awaiting', $stored['ru']);
            } elseif ('returnToAuthor' === $mutation) {
                self::assertArrayHasKey('returned', $stored['ru']);
            } elseif ('unpublish' === $mutation) {
                self::assertSame('draft', $stored['ru']['status']);
            } elseif (\str_starts_with($mutation, 'publish')) {
                self::assertSame('Draft', $stored['ru']['published']['title']);
            }
        } finally {
            $other->close();
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function updateVersionChecks(): iterable
    {
        yield 'without baseVersion' => [false];
        yield 'with baseVersion' => [true];
    }

    #[Test]
    #[DataProvider('updateVersionChecks')]
    public function updateHoldsBothItemLocksBeforeRunningMutationHooks(bool $withVersion): void
    {
        $item = $this->api('POST', '', ['title' => 'Source'], 201);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = DriverManager::getConnection($em->getConnection()->getParams());
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $checked = false;
        $listener = static function (RecordUpdating $event) use ($item, $other, &$checked): void {
            if (self::COLLECTION !== $event->resourceKey || $event->record->id !== $item['id']) {
                return;
            }
            $checked = true;
            $key = EditLock::key('item', self::COLLECTION . ':' . $item['id']);
            $acquired = $other->fetchOne('SELECT pg_try_advisory_lock(?)', [$key]);
            if ($acquired) {
                $other->fetchOne('SELECT pg_advisory_unlock(?)', [$key]);
            }
            self::assertFalse((bool) $acquired, 'The shared item advisory lock must be held before updating.');
            try {
                $other->fetchOne('SELECT id FROM itk_collection_item WHERE id = ? FOR UPDATE NOWAIT', [$item['id']]);
                self::fail('The item must already be refreshed with PESSIMISTIC_WRITE.');
            } catch (DriverException $error) {
                self::assertSame('55P03', $error->getSQLState());
            }
        };
        $dispatcher->addListener(RecordUpdating::class, $listener);
        try {
            $this->service()->update(self::COLLECTION, $item['id'], ['title' => 'Edited'], 'ru', baseVersion: $withVersion ? $item['version'] : null);
            self::assertTrue($checked);
        } finally {
            $dispatcher->removeListener(RecordUpdating::class, $listener);
            $other->close();
        }
    }

    #[Test]
    public function translationEventsAreDiscardedWhenTheOuterTransactionRollsBack(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source'], 201)['id'];
        $events = [];
        foreach ([RecordTranslationAdded::class, RecordTranslationRemoved::class] as $class) {
            self::getContainer()->get('event_dispatcher')->addListener($class, static function ($event) use (&$events): void {
                if (self::COLLECTION === $event->resourceKey) {
                    $events[] = $event;
                }
            });
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hooks = self::getContainer()->get(\Itk\Cms\Resource\HookDispatcher::class);
        foreach (['add', 'remove'] as $operation) {
            if ('remove' === $operation) {
                $this->service()->addTranslation(self::COLLECTION, $id, 'kk', 'ru');
                $events = [];
            }
            try {
                $hooks->deferAfter(fn () => $em->getConnection()->transactional(function () use ($id, $operation): void {
                    if ('add' === $operation) {
                        $this->service()->addTranslation(self::COLLECTION, $id, 'kk', 'ru');
                    } else {
                        $this->service()->removeTranslation(self::COLLECTION, $id, 'kk');
                    }
                    throw new \RuntimeException('roll back translation');
                }));
                self::fail('Expected a rollback.');
            } catch (\RuntimeException $error) {
                self::assertSame('roll back translation', $error->getMessage());
            }
            self::assertSame([], $events);
            $em->clear();
            $stored = $em->find(CollectionItem::class, $id);
            self::assertNotNull($stored);
            self::assertSame('remove' === $operation, isset($stored->translations['kk']));
        }
    }

    #[Test]
    public function copyingRawContentRetainsTheCreationGuardAndCanStillCreateABlankTranslation(): void
    {
        $id = $this->api('POST', '', ['title' => 'Source', 'html' => '<p>Trusted source</p>'], 201)['id'];
        $this->signIn('editor');
        $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => 'ru'], 403);
        self::assertFalse($this->api('GET', '/' . $id . '?locale=kk')['translated']);
        $blank = $this->api('POST', '/' . $id . '/translations?locale=kk', ['from' => null], 201);
        self::assertSame('', $blank['html']);
    }
}

final class TranslationItemsCollection extends CollectionResource
{
    /** @var list<string> */
    public static array $lifecycle = [];

    public function beforeCreating(array $data, Context $ctx): array
    {
        self::$lifecycle[] = 'beforeCreating';

        return $data;
    }

    public function afterCreated(Record $record, Context $ctx): void
    {
        self::$lifecycle[] = 'afterCreated';
    }

    public function beforeDeleting(Record $record, Context $ctx): void
    {
        self::$lifecycle[] = 'beforeDeleting';
    }

    public function afterDeleted(Record $record, Context $ctx): void
    {
        self::$lifecycle[] = 'afterDeleted';
    }

    public function key(): string
    {
        return 'test_translation_items';
    }

    public function title(): string
    {
        return 'Translation items';
    }

    public function tree(): bool
    {
        return true;
    }

    public function fields(): iterable
    {
        yield Text::make('Title', 'title')->required()->default('New item');
        yield Toggle::make('Enabled', 'enabled')->default(true);
        yield Text::make('Secret', 'secret')->canSee('set:users');
        yield Text::make('Locked', 'locked')->readonly();
        yield Textarea::make('HTML', 'html')->raw();
    }
}

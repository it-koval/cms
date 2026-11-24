<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Field\Description;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/** The collection endpoint filters and counts the complete language corpus before slicing it. */
final class CollectionListEnvelopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $key;
    private string $userId;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: [
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        $this->key = 'test_envelope_' . \bin2hex(\random_bytes(6));
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->get(ResourceRegistry::class)->add(new class($this->key) extends CollectionResource {
            public function __construct(private readonly string $collectionKey)
            {
            }

            public function key(): string
            {
                return $this->collectionKey;
            }

            public function title(): string
            {
                return 'Envelope fixtures';
            }

            public function subField(): string
            {
                return 'subtitle';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title')->sortable();
                yield Text::make('Subtitle', 'subtitle');
                yield Text::make('Body', 'body')->searchable();
                yield Repeater::make('Features', 'features')->fields(Text::make('Label', 'label'));
                yield Description::make('Description', 'description')->default('<b>Same description</b>');
            }
        });
        $user = self::getContainer()->get(UserManager::class)->create($this->key . '@example.com', 'Envelope fixtures', 'admin', 'password-for-test');
        $this->userId = $user->id;
        $this->client->loginUser($user, 'itk_admin');
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :key')->setParameter('key', $this->key)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey = :key')->setParameter('key', $this->key)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id = :id')->setParameter('id', $this->userId)->execute();
        parent::tearDown();
    }

    private function collection(): Collection
    {
        return self::getContainer()->get(Schema::class)->collection($this->key);
    }

    private function service(): CollectionService
    {
        return self::getContainer()->get(CollectionService::class);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function api(array $params = [], int $status = 200): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $query = [] === $params ? '' : '?' . \http_build_query($params);
        $this->client->request('GET', '/admin/api/collections/' . $this->key . $query);
        $text = (string) $this->client->getResponse()->getContent();
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $text);

        return (array) \json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function translation(string $title, int $state = 0): array
    {
        $draft = ['title' => $title, 'subtitle' => 'Subtitle ' . $title, 'body' => 'Body-only needle', 'features' => []];
        $published = 0 === $state ? null : $draft;
        if (2 === $state) {
            $published['subtitle'] = 'Previous subtitle';
        }

        return ['draft' => $draft, 'published' => $published, 'status' => 0 === $state ? 'draft' : 'published', 'updatedAt' => '2026-11-24T00:00:00+00:00'];
    }

    /** @param array<string, array<string, mixed>> $translations */
    private function item(array $translations, int $position = 0, ?string $id = null): CollectionItem
    {
        $item = new CollectionItem($this->key, $id);
        $item->position = $position;
        $item->createdAt = new \DateTimeImmutable('2026-11-24T00:00:00+00:00');
        $item->translations = $translations;
        self::getContainer()->get(EntityManagerInterface::class)->persist($item);

        return $item;
    }

    /** @return array{all: list<string>, translated: list<string>, missing: list<string>} */
    private function corpus(int $count = 230): array
    {
        $ids = ['all' => [], 'translated' => [], 'missing' => []];
        for ($n = 0; $n < $count; ++$n) {
            $translations = ['ru' => $this->translation('RU ' . $n, $n % 3)];
            if (0 === $n % 2) {
                $translations['kk'] = $this->translation('KK ' . $n, \intdiv($n, 2) % 3);
            }
            $item = $this->item($translations, $n);
            $ids['all'][] = $item->id;
            $ids[0 === $n % 2 ? 'translated' : 'missing'][] = $item->id;
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $ids;
    }

    #[Test]
    public function translatedAndMissingTotalsAndIdentitiesAreCalculatedBeforePagination(): void
    {
        $ids = $this->corpus();
        foreach (['translated' => ['onlyTranslated' => 1], 'missing' => ['status' => 'missing']] as $kind => $filter) {
            $seen = [];
            foreach ([1 => 50, 2 => 50, 3 => 15, 4 => 0] as $page => $count) {
                $result = $this->api($filter + ['locale' => 'kk', 'perPage' => 50, 'page' => $page]);
                self::assertSame([115, $page, 50], [$result['total'], $result['page'], $result['perPage']]);
                self::assertCount($count, $result['items']);
                self::assertSame(\array_slice($ids[$kind], ($page - 1) * 50, 50), \array_column($result['items'], 'id'));
                foreach ($result['items'] as $row) {
                    self::assertSame('translated' === $kind, $row['translated']);
                    self::assertSame('missing' === $kind, 'missing' === $row['status']);
                }
                $seen = [...$seen, ...\array_column($result['items'], 'id')];
            }
            self::assertSame($ids[$kind], $seen);
            self::assertCount(115, \array_unique($seen));
        }
        self::assertSame([], \array_intersect($ids['translated'], $ids['missing']));
        $all = $this->api(['locale' => 'kk', 'perPage' => 999]);
        self::assertSame([230, 1, 200], [$all['total'], $all['page'], $all['perPage']]);
        self::assertSame(\array_slice($ids['all'], 0, 200), \array_column($all['items'], 'id'));
        $outside = $this->api(['locale' => 'kk', 'page' => 999, 'perPage' => 50]);
        self::assertSame([230, 999, 50, []], [$outside['total'], $outside['page'], $outside['perPage'], $outside['items']]);
    }

    #[Test]
    public function workflowFiltersMatchLegacyRowsIncludingPublishedRecordsWithDraftChanges(): void
    {
        $this->corpus();
        foreach (['ru', 'kk'] as $locale) {
            $rows = $this->service()->list($this->key, locale: $locale);
            $changedPublished = \array_filter($rows, static fn (array $row): bool => 'published' === $row['status'] && $row['changed']);
            self::assertNotEmpty($changedPublished);
            foreach (['draft', 'published'] as $status) {
                $expected = \array_values(\array_filter($rows, static fn (array $row): bool => $row['translated'] && ('draft' === $status ? ('draft' === $row['status'] || $row['changed']) : ('draft' !== $row['status'] && !$row['changed']))));
                self::assertSame(\array_column($expected, 'id'), \array_column($this->service()->list($this->key, status: $status, locale: $locale), 'id'));
                $result = $this->api(['locale' => $locale, 'status' => $status, 'perPage' => 200]);
                self::assertSame(\count($expected), $result['total']);
                self::assertSame(\array_column($expected, 'id'), \array_column($result['items'], 'id'));
                $page = $this->api(['locale' => $locale, 'status' => $status, 'sort' => '-position', 'perPage' => 17, 'page' => 2]);
                self::assertSame(\count($expected), $page['total']);
                self::assertSame(\array_slice(\array_reverse(\array_column($expected, 'id')), 17, 17), \array_column($page['items'], 'id'));
                $legacyFilter = $this->api(['locale' => $locale, 'filter' => ['status' => $status], 'perPage' => 200]);
                $legacyExpected = \array_values(\array_filter($rows, static fn (array $row): bool => $row['translated'] && $status === $row['status']));
                self::assertSame(\count($legacyExpected), $legacyFilter['total']);
                self::assertSame(\array_column($legacyExpected, 'id'), \array_column($legacyFilter['items'], 'id'));
            }
        }
    }

    #[Test]
    public function cosmeticRepeaterIdsAndObsoleteKeysDoNotMakeAPublishedRecordADraft(): void
    {
        $translation = $this->translation('Unchanged', 1);
        $translation['draft']['features'] = [['_id' => 'new-id', 'label' => 'Same']];
        $translation['published']['features'] = [['_id' => 'old-id', 'label' => 'Same']];
        $translation['draft']['obsolete'] = 'New unknown value';
        $translation['published']['obsolete'] = 'Old unknown value';
        $item = $this->item(['ru' => $translation]);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $row = $this->service()->list($this->key)[0];
        self::assertFalse($row['changed']);
        $published = $this->api(['status' => 'published'])['items'];
        self::assertSame([$item->id], \array_column($published, 'id'));
        self::assertSame($this->service()->list($this->key, status: 'published'), $published);
        self::assertSame([], $this->api(['status' => 'draft'])['items']);
    }

    #[Test]
    public function savedHtmlEquivalentToThePublishedDefaultStillCountsAsPublished(): void
    {
        $service = $this->service();
        $id = $service->create($this->key)['id'];
        $service->publish($this->key, $id);
        $edited = $service->update($this->key, $id, ['description' => '<strong>Same description</strong>']);
        self::assertFalse($edited['changed']);
        $published = $this->api(['status' => 'published'])['items'];
        self::assertSame([$id], \array_column($published, 'id'));
        self::assertSame($service->list($this->key, status: 'published'), $published);
        self::assertSame([], $this->api(['status' => 'draft'])['items']);

        $edited = $service->update($this->key, $id, ['description' => '<p>Different description</p>']);
        self::assertTrue($edited['changed']);
        $drafts = $this->api(['status' => 'draft'])['items'];
        self::assertSame([$id], \array_column($drafts, 'id'));
        self::assertSame($service->list($this->key, status: 'draft'), $drafts);
        self::assertSame([], $this->api(['status' => 'published'])['items']);
    }

    #[Test]
    public function searchUsesTitleAndSubtitleWithFallbackForMissingLanguages(): void
    {
        $title = $this->item(['ru' => $this->translation('Heading needle')], 0);
        $translation = $this->translation('Other');
        $translation['draft']['subtitle'] = 'Subtitle needle';
        $subtitle = $this->item(['ru' => $translation], 1);
        $this->item(['ru' => $this->translation('No match')], 2);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        foreach (['ru', 'kk'] as $locale) {
            foreach (['HEADING NEEDLE' => [$title->id], 'subtitle needle' => [$subtitle->id], 'needle subtitle' => [$title->id], 'needle' => [$title->id, $subtitle->id], 'body-only' => []] as $query => $expected) {
                $result = $this->api(['q' => $query, 'locale' => $locale, 'perPage' => 1]);
                self::assertSame(\count($expected), $result['total']);
                self::assertSame(\array_slice($expected, 0, 1), \array_column($result['items'], 'id'));
                self::assertSame($expected, \array_column($this->service()->list($this->key, $query, locale: $locale), 'id'));
            }
        }
        $filtered = $this->api(['q' => 'body-only', 'filter' => ['status' => 'draft']]);
        self::assertSame(3, $filtered['total'], 'explicit field-query filters retain searchable-field search');
        $fallback = $this->item(['tr' => $this->translation('First fallback needle')], 3);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $result = $this->api(['locale' => 'kk', 'q' => 'first fallback']);
        self::assertSame(1, $result['total']);
        self::assertSame([$fallback->id], \array_column($result['items'], 'id'));
        self::assertSame($this->service()->list($this->key, 'first fallback', locale: 'kk'), $result['items']);
    }

    #[Test]
    public function defaultsAndDuplicateSortValuesReturnADeterministicEnvelope(): void
    {
        $ids = [];
        for ($n = 0; $n < 4; ++$n) {
            $ids[] = Uuid::v4()->toRfc4122();
        }
        \sort($ids, \SORT_STRING);
        foreach (\array_reverse($ids) as $id) {
            $this->item(['ru' => $this->translation('Duplicate')], id: $id);
        }
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $default = $this->api();
        self::assertSame([4, 1, 50], [$default['total'], $default['page'], $default['perPage']]);
        self::assertSame($ids, \array_column($default['items'], 'id'));
        self::assertArrayHasKey('facets', $default);
        self::assertArrayHasKey('metrics', $default);
        self::assertSame($default, $this->api(['envelope' => 0]));
        self::assertSame($default, $this->api(['envelope' => 1]));
        $seen = [];
        foreach ([1, 2] as $page) {
            $result = $this->api(['sort' => 'title', 'perPage' => 2, 'page' => $page]);
            self::assertSame(\array_slice($ids, ($page - 1) * 2, 2), \array_column($result['items'], 'id'));
            $seen = [...$seen, ...\array_column($result['items'], 'id')];
        }
        self::assertSame($ids, $seen);
    }

    #[Test]
    public function enabledLanguageWithoutVersionsHasZeroTranslatedAndAllMissingRows(): void
    {
        $ids = $this->corpus(6);
        foreach ([[], ['status' => 'missing']] as $params) {
            $result = $this->api($params + ['locale' => 'en']);
            self::assertSame(6, $result['total']);
            self::assertSame($ids['all'], \array_column($result['items'], 'id'));
        }
        foreach ([['onlyTranslated' => 1], ['status' => 'draft'], ['status' => 'published']] as $params) {
            $result = $this->api($params + ['locale' => 'en']);
            self::assertSame(0, $result['total']);
            self::assertSame([], $result['items']);
        }
        $error = $this->api(['status' => 'unknown'], 422)['error'];
        self::assertStringContainsString('draft, published', $error);
    }

    #[Test]
    public function statusFacetsMatchUnpublishedChangesAndMissingTranslationFilters(): void
    {
        $this->item(['ru' => $this->translation('Main only')]);
        $this->item(['ru' => $this->translation('Draft'), 'kk' => $this->translation('Draft KK')], 1);
        $this->item(['ru' => $this->translation('Changed'), 'kk' => $this->translation('Changed KK', 2)], 2);
        $this->item(['ru' => $this->translation('Live'), 'kk' => $this->translation('Live KK', 1)], 3);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $facets = $this->api(['locale' => 'kk'])['facets']['status'];
        self::assertSame(2, $facets['draft']);
        self::assertSame(1, $facets['published']);
        self::assertSame(1, $facets['missing']);
        foreach ($facets as $status => $count) {
            self::assertSame($count, $this->api(['locale' => 'kk', 'status' => $status])['total']);
        }
        self::assertSame(2, $this->api(['locale' => 'kk', 'filter' => ['status' => 'published']])['total'], 'Legacy raw stored-status filtering remains available.');
    }

    #[Test]
    public function readingASingletonReturnsInitialStateAndKeepsTheMatrixRowShape(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->collection()->singleton($id)->matrix(['colFields' => [], 'rowKinds' => [], 'rowLabel' => 'Row']);
        $result = $this->api();
        self::assertSame([1, 1, 50], [$result['total'], $result['page'], $result['perPage']]);
        self::assertSame([$id], \array_column($result['items'], 'id'));
        self::assertSame([], $result['items'][0]['cols']);
        self::assertSame([], $result['items'][0]['rows']);
        self::assertSame($this->service()->list($this->key), $result['items']);
        $missing = $this->api(['locale' => 'kk', 'status' => 'missing']);
        self::assertSame(1, $missing['total']);
        self::assertSame($this->service()->list($this->key, locale: 'kk'), $missing['items']);
        self::assertSame(0, $this->api(['locale' => 'kk', 'onlyTranslated' => 1])['total']);
    }

    #[Test]
    public function matrixIgnoresCosmeticRowIdsAndDetectsChangedColumns(): void
    {
        $this->collection()->matrix(['colFields' => [], 'rowKinds' => [], 'rowLabel' => 'Row']);
        $cosmetic = $this->translation('Matrix', 1);
        $cosmetic['draft']['cols'] = $cosmetic['published']['cols'] = [['id' => 'one', 'title' => 'Column']];
        $cosmetic['draft']['rows'] = [['_id' => 'new', 'kind' => 'text', 'label' => 'Same']];
        $cosmetic['published']['rows'] = [['_id' => 'old', 'kind' => 'text', 'label' => 'Same']];
        $unchanged = $this->item(['ru' => $cosmetic], 0);
        $changed = $cosmetic;
        $changed['draft']['cols'][0]['title'] = 'Changed column';
        $draft = $this->item(['ru' => $changed], 1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame([$unchanged->id], \array_column($this->api(['status' => 'published'])['items'], 'id'));
        self::assertSame([$draft->id], \array_column($this->api(['status' => 'draft'])['items'], 'id'));
        self::assertSame($this->service()->list($this->key), $this->api()['items']);
    }
}

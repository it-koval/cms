<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Content\CollectionImporter;
use Itk\Cms\Content\CollectionQuery;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Field\Image;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Toggle;
use Itk\Cms\KnowledgeBase\Indexer;
use Itk\Cms\KnowledgeBase\Model\Outcome;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\Metric;
use Itk\Cms\Resource\Record;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

final class RuntimeRecoveryRegressionTest extends KernelTestCase
{
    private string $key;
    private ?string $trigger = null;
    private CollectionResource $resource;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: ['knowledge_base' => ['enabled' => true], 'locales' => ['ru' => ['name' => 'Русский'], 'en' => ['name' => 'English']]]);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->key = 'test_recovery_' . \bin2hex(\random_bytes(6));
        $this->resource = new class($this->key) extends CollectionResource {
            /** @var list<string> */
            public array $log = [];

            public function __construct(private string $collectionKey)
            {
            }

            public function key(): string
            {
                return $this->collectionKey;
            }

            public function title(): string
            {
                return 'Recovery fixture';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title')->default('Initial');
                yield Number::make('Seats', 'seats')->default(7);
                yield Image::make('Image', 'image');
                yield Toggle::make('Hot', 'hot');
            }

            public function metrics(): iterable
            {
                yield Metric::count('All');
                yield Metric::count('Initial', ['title' => 'Initial']);
                yield Metric::count('Other', ['title' => 'Other']);
                yield Metric::sum('Seats', 'seats');
                yield Metric::count('Not hot', ['hot' => false]);
                yield Metric::count('Seats range', ['seats' => ['from' => 5, 'to' => 10]]);
                yield Metric::count('Titles', ['title' => ['Initial', 'Other']]);
            }

            public function beforeUpdating(Record $record, array $data, Context $ctx): array
            {
                $this->log[] = 'before:' . $record->get('title');
                $data['title'] = \trim($data['title'] ?? '');

                return $data;
            }

            public function afterUpdated(Record $record, Context $ctx): void
            {
                $this->log[] = 'after:' . $record->get('title');
            }
        };
        self::getContainer()->get(ResourceRegistry::class)->add($this->resource);
    }

    protected function tearDown(): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        if (!$registry->getManager()->isOpen()) {
            $registry->resetManager();
        }
        $db = self::getContainer()->get(Connection::class);
        if (null !== $this->trigger) {
            $db->executeStatement('DROP TRIGGER IF EXISTS ' . $this->trigger . ' ON itk_kb_document');
            $db->executeStatement('DROP FUNCTION IF EXISTS ' . $this->trigger . '()');
        }
        $db->executeStatement('DELETE FROM itk_collection_item WHERE collection_key = ?', [$this->key]);
        $db->executeStatement('DELETE FROM itk_revision WHERE resource_key = ?', [$this->key]);
        parent::tearDown();
    }

    /** @return iterable<array-key, mixed> */
    public static function badRows(): iterable
    {
        yield 'PostgreSQL jsonb NUL rejection' => [['title' => "bad\0row"]];
        yield 'DBAL invalid UTF-8 conversion' => [['title' => "bad\xFFrow"]];
        yield 'raw conversion failure' => [['image' => new \stdClass()]];
        yield 'infinite numeric string' => [['seats' => '1e999']];
        yield 'NaN float' => [['seats' => \NAN]];
    }

    /** @param array<string, mixed> $bad */
    #[Test]
    #[DataProvider('badRows')]
    public function anInvalidRowIsReportedAndTheSameInjectedServicesKeepWorking(array $bad): void
    {
        $c = self::getContainer();
        $service = $c->get(CollectionService::class);
        $repo = $c->get(CollectionItemRepository::class);
        $em = $c->get(EntityManagerInterface::class);
        $importer = $c->get(CollectionImporter::class);
        $repo->findByCollection($this->key); // initialize the repository before resetManager()
        $result = $importer->import($this->key, [['good-before', ['title' => 'Before']], ['bad-row', $bad], ['good-after', ['title' => 'After']]], 'test:' . $this->key);
        self::assertSame(2, $result->created);
        self::assertCount(1, $result->failed);
        self::assertStringContainsString('2 (bad-row)', $result->failed[0]);
        self::assertTrue($em->isOpen(), 'the injected manager must follow the registry reset');
        self::assertCount(2, $repo->findByCollection($this->key), 'an already initialized repository must use the reset manager');
        self::assertSame(['Before', 'After'], \array_column($service->list($this->key), 'title'));
        $again = $importer->import($this->key, [['good-after', ['title' => 'Updated']]], 'test:' . $this->key);
        self::assertSame([0, 1, []], [$again->created, $again->updated, $again->failed]);
    }

    #[Test]
    public function aDryRunReportsConversionAndNonFiniteNumbersWithoutWriting(): void
    {
        $c = self::getContainer();
        $result = $c->get(CollectionImporter::class)->import($this->key, [
            ['before', ['title' => 'Before']],
            ['infinite', ['seats' => '1e999']],
            ['conversion', ['image' => new \stdClass()]],
            ['after', ['title' => 'After']],
        ], 'test:' . $this->key, dryRun: true);
        self::assertSame(2, $result->created);
        self::assertCount(2, $result->failed);
        self::assertStringContainsString('2 (infinite)', $result->failed[0]);
        self::assertStringContainsString('3 (conversion)', $result->failed[1]);
        self::assertSame(0, (int) $c->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM itk_collection_item WHERE collection_key = ?', [$this->key]));
    }

    #[Test]
    public function theFirstSingletonPatchRunsUpdateHooks(): void
    {
        $c = self::getContainer();
        $schema = $c->get(Schema::class)->collection($this->key)->singleton(Uuid::v7()->toRfc4122());
        $service = $c->get(CollectionService::class);
        $initial = $service->get($this->key, $schema->singletonId())['item'];
        $saved = $service->update($this->key, $schema->singletonId(), ['title' => ' Saved '], baseVersion: $initial['version']);
        // @phpstan-ignore property.notFound (The anonymous test resource records its hook calls in $log.)
        self::assertSame(['before:Initial', 'after:Saved'], $this->resource->log);
        self::assertSame('Saved', $saved['title']);
        $history = $c->get(Connection::class)->fetchAllAssociative('SELECT action, snapshot FROM itk_revision WHERE resource_id = ?', [$schema->singletonId()]);
        self::assertCount(1, $history, 'the first PATCH is one creation history entry');
        self::assertSame('create', $history[0]['action']);
        self::assertStringContainsString('Saved', $history[0]['snapshot']);
    }

    #[Test]
    public function aVirtualSingletonHasItsMetricsWithoutWritingAnything(): void
    {
        $c = self::getContainer();
        $schema = $c->get(Schema::class)->collection($this->key)->singleton(Uuid::v7()->toRfc4122());
        $query = $c->get(CollectionQuery::class);
        $before = $query->run($schema, ['q' => 'no match']);
        self::assertSame(0, $before['total']);
        self::assertSame([1, 1, 0, 7, 1, 1, 1], \array_column($before['metrics'], 'value'), 'metrics cover the corpus, independently of the list filter');
        self::assertSame([1, 0, 0, 0, 1, 0, 0], \array_column($query->run($schema, [], 'en')['metrics'], 'value'));
        $db = $c->get(Connection::class);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_collection_item WHERE collection_key = ?', [$this->key]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_revision WHERE resource_key = ?', [$this->key]));
        $c->get(CollectionService::class)->create($this->key);
        self::assertSame($before['metrics'], $query->run($schema, [])['metrics'], 'materialization alone must not change the metric corpus');
        self::assertSame([1, 0, 0, 0, 1, 0, 0], \array_column($query->run($schema, [], 'en')['metrics'], 'value'));
    }

    /** @return iterable<array-key, mixed> */
    public static function transientFailures(): iterable
    {
        yield 'deadlock' => ['40P01'];
        yield 'connection failure' => ['08006'];
    }

    #[Test]
    #[DataProvider('transientFailures')]
    public function theCliReportsATransientFailureAndContinuesWithTheNextSource(string $sqlState): void
    {
        $c = self::getContainer();
        $service = $c->get(CollectionService::class);
        $item = $service->create($this->key, ['title' => 'Russian']);
        $service->publish($this->key, $item['id']);
        $service->addTranslation($this->key, $item['id'], 'en', null);
        $service->update($this->key, $item['id'], ['title' => 'English'], 'en');
        $service->publish($this->key, $item['id'], 'en');
        $db = $c->get(Connection::class);
        $this->trigger = 'test_kb_' . \bin2hex(\random_bytes(6));
        $db->executeStatement('CREATE FUNCTION ' . $this->trigger . "() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.source_id = ''" . $item['id'] . "'' AND NEW.locale = ''ru'' THEN RAISE EXCEPTION ''fixture deadlock'' USING ERRCODE = ''" . $sqlState . "''; END IF; RETURN NEW; END'");
        $db->executeStatement('CREATE TRIGGER ' . $this->trigger . ' BEFORE INSERT ON itk_kb_document FOR EACH ROW EXECUTE FUNCTION ' . $this->trigger . '()');
        try {
            $c->get(Indexer::class)->sync([new SourceRef(SourceKind::Item, $item['id'], 'ru')]);
            self::fail('the worker path must still throw temporary failures for Messenger retry');
        } catch (ConnectionException|RetryableException $e) {
            self::assertStringContainsString('fixture deadlock', $e->getMessage());
        }
        $first = $service->create($this->key, ['title' => 'Before failure']);
        $service->publish($this->key, $first['id']);
        $last = $service->create($this->key, ['title' => 'After failure']);
        $service->publish($this->key, $last['id']);
        $refs = \array_map(static fn (array $row): SourceRef => new SourceRef(SourceKind::Item, $row['id'], 'ru'), [$first, $item, $last]);
        $report = $c->get(Indexer::class)->sync($refs, retryTransient: false);
        self::assertSame([Outcome::Written, Outcome::Failed, Outcome::Written], \array_values($report->outcomes));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_kb_document WHERE resource_key = ?', [$this->key]));
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:knowledge-base:sync'));
        $tester->execute(['--now' => true, '--source' => 'item:' . $item['id']]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('fixture deadlock', $tester->getDisplay());
        self::assertStringContainsString('неудач: 1', $tester->getDisplay());
        self::assertStringContainsString('без изменений: 1', $tester->getDisplay(), 'the next source is still processed (non-main locales are excluded from indexing)');
        self::assertSame([], $db->fetchFirstColumn('SELECT locale FROM itk_kb_document WHERE source_id = ?', [$item['id']]));
    }
}

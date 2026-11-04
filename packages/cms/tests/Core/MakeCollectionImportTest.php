<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Command\MakeCollectionCommand;
use Itk\Cms\Content\CollectionImporter;
use Itk\Cms\Generator\CollectionClassBuilder;
use Itk\Cms\Generator\CollectionSpecReader;
use Itk\Cms\Generator\FileWriter;
use Itk\Cms\Generator\MakeContext;
use Itk\Cms\Generator\ProjectLayout;
use Itk\Cms\Generator\SkeletonRenderer;
use Itk\Cms\Generator\TableReader;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Tests\Core\Fixture\SourceTable;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * itk:make:collection --from=table:… --import на настоящей таблице: класс создаётся, строки переносятся, повторный запуск обновляет.
 * Схема строится только после того, как класс создан (как в консоли), поэтому здесь нет общего ResourceKernelTestCase, который строит её заранее.
 */
final class MakeCollectionImportTest extends KernelTestCase
{
    use SourceTable;

    private string $project = '';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->dropSourceTable();
        if ('' !== $this->project) {
            $this->remove($this->project);
        }
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $f) {
                '.' !== $f && '..' !== $f && $this->remove($path . '/' . $f);
            }
            \rmdir($path);
        } elseif (\file_exists($path)) {
            \unlink($path);
        }
    }

    #[Test]
    public function theCommandMakesTheClassAndImportsTheRowsOfATable(): void
    {
        $this->table();
        $this->project = \sys_get_temp_dir() . '/itk_imp_' . \bin2hex(\random_bytes(4));
        \mkdir($this->project, 0775, true);
        \file_put_contents($this->project . '/composer.json', '{"autoload": {"psr-4": {"Gen\\\\": "src/"}}}');
        $container = self::getContainer();
        $make = new MakeContext(new ProjectLayout($this->project), new SkeletonRenderer(), new FileWriter(), $container->get(ResourceRegistry::class), $this->project . '/none.php');
        $later = new class(static fn () => self::getContainer()->get(CollectionImporter::class)) implements ContainerInterface {
            public function __construct(private readonly \Closure $importer)
            {
            }

            public function get(string $id): object
            {
                return ($this->importer)();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $run = static function (array $input) use ($make, $container, $later): CommandTester {
            $cmd = new MakeCollectionCommand($make, new CollectionClassBuilder(new SkeletonRenderer()), new CollectionSpecReader(), new TableReader(), $container->get('doctrine'), $later);
            $t = new CommandTester($cmd);
            $t->execute($input, ['interactive' => false]);

            return $t;
        };
        $args = ['key' => 'test_gen_trips', 'name' => 'Поездки из таблицы', '--from' => 'table:' . self::TABLE, '--map' => 'starts_at:starts_at:date'];

        // a dry run: the plan and the first rows, no file, no record
        $t = $run($args + ['--import' => true, '--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode(), $t->getDisplay());
        self::assertStringContainsString('будет создано: 3, будет обновлено: 0', $t->getDisplay());
        self::assertStringContainsString('Пробный запуск', $t->getDisplay());
        self::assertFileDoesNotExist($this->project . '/src/Itk/Collection/TestGenTripsCollection.php');
        self::assertSame(0, $this->itemCount('test_gen_trips'));

        // the real run
        $t = $run($args + ['--import' => true, '--publish' => true]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode(), $t->getDisplay());
        self::assertStringContainsString('создано: 3, обновлено: 0', $t->getDisplay());
        self::assertFileExists($this->project . '/src/Itk/Collection/TestGenTripsCollection.php');
        self::assertSame(3, $this->itemCount('test_gen_trips'));
        $items = $this->records('test_gen_trips');
        self::assertSame(['Алтай', 'Байкал', 'Карелия'], \array_column($items, 'city'));
        self::assertSame(['2026-12-01', '', '2026-11-20'], \array_column($items, 'starts_at'), 'a date column mapped to Date, a NULL is empty');
        self::assertSame([true, false, true], \array_column($items, 'hot'));
        self::assertSame([12.5, '', 7], \array_column($items, 'price'), 'decimals arrive as numbers; NULL is empty');
        self::assertSame(['published', 'published', 'published'], \array_column($items, 'status'));

        // again: the class is kept, nothing is made twice, a changed row is updated, a new row is added
        $this->db()->executeStatement('UPDATE ' . self::TABLE . " SET city = 'Алтай-2' WHERE id = 1");
        $this->db()->executeStatement('INSERT INTO ' . self::TABLE . " (city, level) VALUES ('Сочи', 'hard')");
        $t = $run($args + ['--import' => true]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode(), $t->getDisplay());
        self::assertStringContainsString('создано: 1, обновлено: 3', $t->getDisplay());
        self::assertStringContainsString('класс уже есть', $t->getDisplay());
        self::assertSame(4, $this->itemCount('test_gen_trips'));
        self::assertContains('Алтай-2', \array_column($this->records('test_gen_trips'), 'city'));

        // without --import the existing class is not overwritten
        $t = $run($args);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('--force', $t->getDisplay());
    }

    private function itemCount(string $collection): int
    {
        return \count(self::getContainer()->get(CollectionItemRepository::class)->findByCollection($collection));
    }

    /** @return list<array<string, mixed>> the draft of every record, in the order of the source rows */
    private function records(string $collection): array
    {
        $out = [];
        foreach (self::getContainer()->get(CollectionItemRepository::class)->findByCollection($collection) as $i) {
            $out[] = $i->translations['ru']['draft'] + ['status' => $i->translations['ru']['status']];
        }
        \usort($out, static fn (array $a, array $b): int => \strcmp((string) $a['city'], (string) $b['city']));

        return $out;
    }
}

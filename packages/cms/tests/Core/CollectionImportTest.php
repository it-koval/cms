<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionImporter;
use Itk\Cms\Generator\TableReader;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use Itk\Cms\Tests\Core\Fixture\SourceTable;
use PHPUnit\Framework\Attributes\Test;

/**
 * Перенос строк в записи коллекции: ключ строки источника хранится в записи (meta.sourceId), повторный запуск обновляет, а не дублирует.
 * И itk:make:collection --from=table:… --import на настоящей таблице (она создаётся на время теста).
 */
final class CollectionImportTest extends ResourceKernelTestCase
{
    use SourceTable;

    protected function tearDown(): void
    {
        $this->clean();
        $this->dropSourceTable();
        parent::tearDown();
    }

    private function importer(): CollectionImporter
    {
        return self::getContainer()->get(CollectionImporter::class);
    }

    /** @return list<array<string, mixed>> */
    private function tripRows(): array
    {
        return \array_map(fn (array $r): array => $this->collections->get('test_trips', $r['id'])['item'], $this->collections->list('test_trips'));
    }

    #[Test]
    public function rowsBecomeRecordsAndASecondRunUpdatesThem(): void
    {
        $rows = [
            ['10', ['title' => 'Алтай', 'start' => '2026-12-01 10:30:00', 'seats' => '12', 'hot' => '1', 'state' => 'open', 'level' => 'mid', 'unknown' => 'x']],
            ['11', ['title' => 'Байкал', 'start' => '2026-11-15', 'seats' => '8.5', 'hot' => '0', 'state' => 'full', 'level' => 'easy']],
        ];
        $r = $this->importer()->import('test_trips', $rows, 'test:rows');
        self::assertSame([2, 0, []], [$r->created, $r->updated, $r->failed]);
        self::assertSame(['unknown'], $r->ignoredKeys);

        $byTitle = [];
        foreach ($this->tripRows() as $t) {
            $byTitle[$t['title']] = $t;
        }
        self::assertSame('2026-12-01', $byTitle['Алтай']['start'], 'a date field keeps the day');
        self::assertSame([12, true], [$byTitle['Алтай']['seats'], $byTitle['Алтай']['hot']]);
        self::assertSame([8.5, false], [$byTitle['Байкал']['seats'], $byTitle['Байкал']['hot']]);
        self::assertSame('draft', $byTitle['Алтай']['status'], 'imported records are drafts');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $item = self::getContainer()->get(CollectionItemRepository::class)->find($byTitle['Алтай']['id']);
        self::assertSame(['sourceId' => '10', 'source' => 'test:rows'], $item->translations['ru']['meta']);
        $em->clear();

        // the same rows again: nothing is made twice; a changed value is updated
        $rows[0][1]['seats'] = '15';
        $r = $this->importer()->import('test_trips', $rows, 'test:rows');
        self::assertSame([0, 2], [$r->created, $r->updated]);
        self::assertCount(2, $this->collections->list('test_trips'));
        $again = $this->collections->get('test_trips', $byTitle['Алтай']['id'])['item'];
        self::assertSame(15, $again['seats']);

        // a new row is added, the meta survives updates
        $rows[] = ['12', ['title' => 'Карелия', 'seats' => 6]];
        $r = $this->importer()->import('test_trips', $rows, 'test:rows');
        self::assertSame([1, 2], [$r->created, $r->updated]);
        $em->clear();
        self::assertSame('10', self::getContainer()->get(CollectionItemRepository::class)->find($byTitle['Алтай']['id'])->translations['ru']['meta']['sourceId']);
    }

    #[Test]
    public function aDryRunCountsAndWritesNothing(): void
    {
        $rows = [['1', ['title' => 'Урал']], ['2', ['title' => 'Эльбрус']]];
        $r = $this->importer()->import('test_trips', $rows, 'test:rows', dryRun: true);
        self::assertSame([2, 0], [$r->created, $r->updated]);
        self::assertSame('Урал', $r->preview[0][1]['title']);
        self::assertSame([], $this->collections->list('test_trips'));

        $this->importer()->import('test_trips', $rows, 'test:rows');
        $r = $this->importer()->import('test_trips', $rows, 'test:rows', dryRun: true);
        self::assertSame([0, 2], [$r->created, $r->updated]);
    }

    #[Test]
    public function publishingChecksRequiredFieldsRowByRow(): void
    {
        $r = $this->importer()->import('test_trips', [['1', ['title' => 'Урал']], ['2', ['title' => '']], ['3', ['title' => 'Алтай']]], 'test:rows', publish: true);
        self::assertSame(3, $r->created, 'the records exist as drafts');
        self::assertCount(1, $r->failed);
        self::assertStringContainsString('строка 2', $r->failed[0]);
        $status = [];
        foreach ($this->tripRows() as $t) {
            $status[$t['title']] = $t['status'];
        }
        self::assertSame(['Урал' => 'published', '' => 'draft', 'Алтай' => 'published'], $status);
    }

    #[Test]
    public function aCollectionThatTheSchemaDoesNotKnowIsExplained(): void
    {
        $this->expectExceptionMessageMatches('/Коллекции «test_gen_nothing» нет в схеме/u');
        $this->importer()->import('test_gen_nothing', [], 'test');
    }

    #[Test]
    public function columnTypesBecomeFields(): void
    {
        $this->table();
        $spec = (new TableReader())->inspect($this->db(), self::TABLE);

        self::assertSame('test_gen_src', $spec->key);
        self::assertSame('id', $spec->source['idColumn']);
        $by = [];
        foreach ($spec->fields as $f) {
            $by[$f->key] = $f;
        }
        self::assertSame(['city', 'note', 'seats', 'price', 'hot', 'start_on', 'starts_at', 'level'], \array_keys($by), 'the key column and the blob are not fields');
        self::assertSame(['text', true, 40], [$by['city']->type, $by['city']->required, $by['city']->max]);
        self::assertSame(['textarea', false], [$by['note']->type, $by['note']->required]);
        self::assertSame(['number', 'number', 'toggle'], [$by['seats']->type, $by['price']->type, $by['hot']->type]);
        self::assertFalse($by['seats']->required, 'a column with a default value is not required');
        self::assertSame(['date', 'datetime'], [$by['start_on']->type, $by['starts_at']->type]);
        self::assertSame(['select', ['easy', 'hard']], [$by['level']->type, $by['level']->options]);
        self::assertSame('Город', $by['city']->label, 'from the short dictionary');
        self::assertSame('Комментарий из таблицы', $by['note']->label, 'the comment of the column wins');
        self::assertCount(1, $spec->notes);
        self::assertStringContainsString('photo', $spec->notes[0]);

        // --map renames, overrides the type and skips
        $spec = (new TableReader())->inspect($this->db(), self::TABLE, ['city' => ['town', null], 'starts_at' => ['starts_at', 'date'], 'note' => [null, null]]);
        $keys = \array_map(static fn ($f): string => $f->key, $spec->fields);
        self::assertContains('town', $keys);
        self::assertNotContains('note', $keys);
        self::assertSame('date', \array_values(\array_filter($spec->fields, static fn ($f): bool => 'starts_at' === $f->key))[0]->type);
    }

    #[Test]
    public function aMissingTableAndANameThatIsNotATableAreExplained(): void
    {
        foreach (['test_gen_missing', 'a.b.c', 'bad name;'] as $name) {
            try {
                (new TableReader())->inspect($this->db(), $name);
                self::fail($name);
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function aTableWithoutOnePrimaryKeyNeedsTheIdOption(): void
    {
        $this->table(primary: false);
        $spec = (new TableReader())->inspect($this->db(), self::TABLE);
        self::assertNull($spec->source['idColumn']);
        $this->expectExceptionMessageMatches('/--id=колонка/u');
        \iterator_to_array((new TableReader())->rows($this->db(), self::TABLE, $spec));
    }
}

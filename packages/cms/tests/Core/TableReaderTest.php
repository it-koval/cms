<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Generator\TableReader;
use Itk\Cms\Tests\Core\Fixture\SourceTable;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Columns of an existing PostgreSQL table become collection fields. */
final class TableReaderTest extends KernelTestCase
{
    use SourceTable;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->dropSourceTable();
        parent::tearDown();
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
    public function aTableWithoutOnePrimaryKeyKeepsItsIdColumnAsAField(): void
    {
        $this->table(primary: false);
        // Without a single-column primary key, id is an ordinary column; a reserved metadata key needs an explicit mapping.
        $spec = (new TableReader())->inspect($this->db(), self::TABLE, ['id' => ['source_id', null]]);

        self::assertNull($spec->source['idColumn']);
        self::assertContains('source_id', \array_map(static fn ($f): string => $f->key, $spec->fields));
    }
}

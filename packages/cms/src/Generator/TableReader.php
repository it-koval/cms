<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Doctrine\DBAL\Connection;
use Itk\Cms\I18n\I18n;

/**
 * An existing SQL table → the spec of a collection (column types → fields) and its rows.
 *
 *   varchar/char → Text · text → Textarea · int/decimal/float → Number · tinyint(1) → Toggle · date → Date · datetime/timestamp → DateTime · enum → Select
 *
 * @internal
 */
final class TableReader
{
    /**
     * @param array<string, array{0: ?string, 1: ?string}> $map column → [field key or null to skip, type override or null]
     */
    public function inspect(Connection $db, string $table, array $map = [], ?string $idColumn = null): CollectionSpec
    {
        [$schema, $name] = $this->parse($table);
        $rows = $db->fetchAllAssociative(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS col_default, COLUMN_KEY AS col_key, EXTRA AS extra, COLUMN_COMMENT AS comment, CHARACTER_MAXIMUM_LENGTH AS max_length'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$schema, $name],
        );
        if ([] === $rows) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_not_found', ['table' => $table]));
        }

        $primary = \array_values(\array_map(static fn (array $r): string => (string) $r['name'], \array_filter($rows, static fn (array $r): bool => 'PRI' === $r['col_key'])));
        $idColumn ??= 1 === \count($primary) ? $primary[0] : null;
        if (null !== $idColumn && !\in_array($idColumn, \array_column($rows, 'name'), true)) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_no_column', ['table' => $table, 'column' => $idColumn]));
        }

        $fields = [];
        $notes = [];
        foreach ($rows as $r) {
            $column = (string) $r['name'];
            if ($column === $idColumn) {
                continue;
            }
            [$key, $override] = $map[$column] ?? [$column, null];
            if (null === $key) {
                $notes[] = I18n::t('model.gen_column_skipped', ['column' => $column]);
                continue;
            }
            $kind = null !== $override ? ['type' => $override] : $this->kindOf($r);
            if (null === $kind) {
                $notes[] = I18n::t('model.gen_column_type_skipped', ['column' => $column, 'type' => $r['column_type']]);
                continue;
            }
            $label = '' !== \trim((string) $r['comment']) ? \trim((string) $r['comment']) : Naming::label($key);
            $required = 'NO' === $r['nullable'] && null === $r['col_default'] && !\str_contains((string) $r['extra'], 'auto_increment') && 'toggle' !== $kind['type'];
            $fields[] = new FieldSpec($key, $label, $kind['type'], $required, $kind['max'] ?? null, $kind['options'] ?? [], $column);
        }
        if ([] === $fields) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_no_fields', ['table' => $table]));
        }
        $key = Naming::keyOf($name);

        return new CollectionSpec($key, Naming::humanize($key), $fields, source: ['kind' => 'table', 'name' => $table, 'idColumn' => $idColumn], notes: $notes);
    }

    /**
     * The rows of the table: [sourceId, [field key => raw value]] for the columns of the spec.
     *
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public function rows(Connection $db, string $table, CollectionSpec $spec): \Generator
    {
        $idColumn = $spec->source['idColumn'] ?? null;
        if (!\is_string($idColumn) || '' === $idColumn) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_no_primary', ['table' => $table]));
        }
        $columns = [$idColumn];
        foreach ($spec->fields as $f) {
            $columns[] = (string) $f->column;
        }
        $sql = 'SELECT ' . \implode(', ', \array_map($this->quote(...), \array_unique($columns))) . ' FROM ' . $this->quoteTable($table) . ' ORDER BY ' . $this->quote($idColumn);
        foreach ($db->iterateAssociative($sql) as $row) {
            $values = [];
            foreach ($spec->fields as $f) {
                $values[$f->key] = $row[(string) $f->column] ?? null;
            }

            yield [(string) $row[$idColumn], $values];
        }
    }

    /**
     * @param array<string, mixed> $r a row of information_schema.COLUMNS
     *
     * @return array{type: string, max?: int, options?: list<string>}|null
     */
    private function kindOf(array $r): ?array
    {
        $type = \strtolower((string) $r['data_type']);
        $full = \strtolower((string) $r['column_type']);

        return match (true) {
            'tinyint' === $type && \str_starts_with($full, 'tinyint(1)') => ['type' => 'toggle'],
            'bit' === $type && \str_starts_with($full, 'bit(1)') => ['type' => 'toggle'],
            'enum' === $type => ['type' => 'select', 'options' => $this->enumValues($full)],
            \in_array($type, ['varchar', 'char'], true) => ['type' => 'text'] + (null !== $r['max_length'] && (int) $r['max_length'] <= 1000 ? ['max' => (int) $r['max_length']] : []),
            \in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'json'], true) => ['type' => 'textarea'],
            \in_array($type, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'numeric', 'float', 'double', 'year'], true) => ['type' => 'number'],
            'date' === $type => ['type' => 'date'],
            \in_array($type, ['datetime', 'timestamp'], true) => ['type' => 'datetime'],
            'time' === $type => ['type' => 'text'],
            default => null,
        };
    }

    /** @return list<string> */
    private function enumValues(string $columnType): array
    {
        \preg_match_all("/'((?:[^']|'')*)'/", $columnType, $m);

        return \array_map(static fn (string $v): string => \str_replace("''", "'", $v), $m[1]);
    }

    /** @return array{0: ?string, 1: string} [database or null, table] */
    private function parse(string $table): array
    {
        $parts = \explode('.', $table);
        if (\count($parts) > 2 || [] !== \array_filter($parts, static fn (string $p): bool => 1 !== \preg_match('/^[A-Za-z0-9_$]+$/', $p))) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_name_invalid', ['table' => $table]));
        }

        return 2 === \count($parts) ? [$parts[0], $parts[1]] : [null, $parts[0]];
    }

    private function quoteTable(string $table): string
    {
        return \implode('.', \array_map($this->quote(...), \explode('.', $table)));
    }

    private function quote(string $identifier): string
    {
        return '`' . \str_replace('`', '``', $identifier) . '`';
    }
}

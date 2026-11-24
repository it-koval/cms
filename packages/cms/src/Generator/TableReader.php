<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Doctrine\DBAL\Connection;
use Itk\Cms\I18n\I18n;

/**
 * An existing SQL table → the spec of a collection (column types → fields).
 *
 *   varchar/char → Text · text/json → Textarea · integer/numeric/float → Number · boolean → Toggle · date → Date · timestamp → DateTime · enum type → Select
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
            'SELECT column_name AS name, data_type, udt_name, is_nullable AS nullable, column_default AS col_default, is_identity, character_maximum_length AS max_length,'
            . ' pg_catalog.col_description((quote_ident(table_schema) || \'.\' || quote_ident(table_name))::regclass, ordinal_position) AS comment'
            . ' FROM information_schema.columns WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? ORDER BY ordinal_position',
            [$schema, $name],
        );
        if ([] === $rows) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_not_found', ['table' => $table]));
        }
        $primary = $db->fetchFirstColumn(
            'SELECT kcu.column_name FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema AND kcu.table_name = tc.table_name'
            . ' WHERE tc.constraint_type = \'PRIMARY KEY\' AND tc.table_schema = COALESCE(?, current_schema()) AND tc.table_name = ? ORDER BY kcu.ordinal_position',
            [$schema, $name],
        );
        $idColumn ??= 1 === \count($primary) ? (string) $primary[0] : null;
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
            $kind = null !== $override ? ['type' => $override] : $this->kindOf($db, $r);
            if (null === $kind) {
                $notes[] = I18n::t('model.gen_column_type_skipped', ['column' => $column, 'type' => $r['udt_name']]);
                continue;
            }
            $label = '' !== \trim((string) $r['comment']) ? \trim((string) $r['comment']) : Naming::label($key);
            $generated = 'YES' === $r['is_identity'] || \str_starts_with((string) $r['col_default'], 'nextval(');
            $required = 'NO' === $r['nullable'] && null === $r['col_default'] && !$generated && 'toggle' !== $kind['type'];
            $fields[] = new FieldSpec($key, $label, $kind['type'], $required, $kind['max'] ?? null, $kind['options'] ?? [], $column);
        }
        if ([] === $fields) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_no_fields', ['table' => $table]));
        }
        $key = Naming::keyOf($name);

        return new CollectionSpec($key, Naming::humanize($key), $fields, source: ['kind' => 'table', 'name' => $table, 'idColumn' => $idColumn], notes: $notes);
    }

    /**
     * @param array<string, mixed> $r a row of information_schema.columns
     *
     * @return array{type: string, max?: int, options?: list<string>}|null
     */
    private function kindOf(Connection $db, array $r): ?array
    {
        $type = \strtolower((string) $r['data_type']);

        return match (true) {
            'boolean' === $type => ['type' => 'toggle'],
            'user-defined' === $type => $this->enumValues($db, (string) $r['udt_name']),
            \in_array($type, ['character varying', 'character'], true) => ['type' => 'text'] + (null !== $r['max_length'] && (int) $r['max_length'] <= 1000 ? ['max' => (int) $r['max_length']] : []),
            \in_array($type, ['text', 'json', 'jsonb'], true) => ['type' => 'textarea'],
            \in_array($type, ['smallint', 'integer', 'bigint', 'numeric', 'real', 'double precision'], true) => ['type' => 'number'],
            'date' === $type => ['type' => 'date'],
            \str_starts_with($type, 'timestamp') => ['type' => 'datetime'],
            \str_starts_with($type, 'time') => ['type' => 'text'],
            default => null,
        };
    }

    /** @return array{type: string, options: list<string>}|null a Select of the labels of an enum type; another user-defined type is skipped */
    private function enumValues(Connection $db, string $typeName): ?array
    {
        $labels = $db->fetchFirstColumn('SELECT e.enumlabel FROM pg_catalog.pg_enum e JOIN pg_catalog.pg_type t ON t.oid = e.enumtypid WHERE t.typname = ? ORDER BY e.enumsortorder', [$typeName]);

        return [] === $labels ? null : ['type' => 'select', 'options' => \array_map('strval', $labels)];
    }

    /** @return array{0: ?string, 1: string} [schema or null, table] */
    private function parse(string $table): array
    {
        $parts = \explode('.', $table);
        if (\count($parts) > 2 || [] !== \array_filter($parts, static fn (string $p): bool => 1 !== \preg_match('/^[A-Za-z0-9_$]+$/', $p))) {
            throw new \InvalidArgumentException(I18n::t('model.gen_table_name_invalid', ['table' => $table]));
        }

        return 2 === \count($parts) ? [$parts[0], $parts[1]] : [null, $parts[0]];
    }
}

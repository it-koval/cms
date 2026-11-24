<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Schema\Schema;

/**
 * Copies rows from anywhere (a SQL table, a JSON file) into records of a collection. The id of the row in the source is kept in the
 * record (translations[<language>].meta.sourceId), so a second run updates the records it made instead of making them again.
 * Every row goes through the normal CollectionService path: the same checks, hooks and events as a record made by hand.
 *
 * @internal
 */
final class CollectionImporter
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly CollectionItemRepository $items,
        private readonly EntityManagerInterface $em,
        private readonly Schema $schema,
        private readonly EditLock $lock,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * @param iterable<array{0: string, 1: array<string, mixed>}> $rows [sourceId, [field key => raw value]]
     * @param string $source where the rows come from («table:modx_tour_list»), kept as meta.source
     */
    public function import(string $collection, iterable $rows, string $source, bool $publish = false, bool $dryRun = false): ImportResult
    {
        $c = $this->schema->collection($collection) ?? throw ContentException::notFound(I18n::t('content.import_collection_unknown', ['collection' => $collection]));
        $fields = [];
        foreach ($c->fieldList() as $f) {
            $fields[$f->key()] = $f;
        }
        $locale = $this->collections->mainLocale();
        $known = $this->index($collection, $locale);

        $result = new ImportResult();
        $rowNumber = 0;
        foreach ($rows as [$sourceId, $values]) {
            ++$rowNumber;
            $sourceId = (string) $sourceId;
            $writing = false;
            try {
                $data = [];
                foreach ($values as $key => $raw) {
                    if (isset($fields[$key])) {
                        $data[$key] = $this->convert($fields[$key], $raw);
                    } elseif (!\in_array($key, $result->ignoredKeys, true)) {
                        $result->ignoredKeys[] = (string) $key;
                    }
                }
                if (\count($result->preview) < 5) {
                    $result->preview[] = [$sourceId, $data];
                }
                $id = $known[$sourceId] ?? null;
                if ($dryRun) {
                    null === $id ? ++$result->created : ++$result->updated;
                    continue;
                }
                $writing = true;
                $id = $this->lock->transactional(function () use ($collection, $id, $data, $sourceId, $source, $locale): string {
                    if (null === $id) {
                        $id = (string) $this->collections->create($collection, $data)['id'];
                    } else {
                        $this->collections->update($collection, $id, $data);
                    }
                    $this->remember($id, $locale, $sourceId, $source);

                    return $id;
                });
                null === ($known[$sourceId] ?? null) ? ++$result->created : ++$result->updated;
                $known[$sourceId] = $id;
                if ($publish) {
                    $this->collections->publish($collection, $id);
                }
            } catch (\Throwable $e) {
                if (!$this->em->isOpen()) {
                    // DoctrineBundle resets the shared lazy manager in place, including already injected services and repositories.
                    $this->registry->resetManager();
                } elseif ($writing) {
                    $this->em->clear(); // discard objects changed by the rolled-back row
                }
                $row = (string) $rowNumber;
                if ($sourceId !== $row) {
                    $row .= ' (' . $sourceId . ')';
                }
                $result->failed[] = I18n::t('content.import_row_failed', ['row' => $row, 'message' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /** @return array<string, string> sourceId → record id */
    private function index(string $collection, string $locale): array
    {
        $out = [];
        foreach ($this->items->findByCollection($collection) as $i) {
            $sourceId = $i->translations[$locale]['meta']['sourceId'] ?? null;
            if (null !== $sourceId) {
                $out[(string) $sourceId] = $i->id;
            }
        }

        return $out;
    }

    private function remember(string $id, string $locale, string $sourceId, string $source): void
    {
        $item = $this->items->find($id);
        if (!$item instanceof CollectionItem || !isset($item->translations[$locale])) {
            return;
        }
        $all = $item->translations;
        $all[$locale]['meta'] = ['sourceId' => $sourceId, 'source' => $source];
        $item->translations = $all; // reassign: Doctrine tracks the JSON column by value
        $this->em->flush();
    }

    /** A raw value of the source (SQL gives strings and nulls) → the value the field stores. */
    private function convert(FieldInterface $f, mixed $raw): mixed
    {
        $shape = $f->toArray();
        $input = $shape['inputType'] ?? null;

        return match (true) {
            'toggle' === $f->type() => \in_array(\is_string($raw) ? \strtolower(\trim($raw)) : $raw, [true, 1, '1', 'true', 'yes', 'on', 'да'], true), // i18n-ignore: «yes» as it is typed in a source table
            'number' === $f->type() => \is_numeric($raw) ? $this->number($raw) : '',
            'date' === $input => $this->date($raw, 'Y-m-d'),
            'datetime-local' === $input => $this->date($raw, 'Y-m-d\TH:i'),
            'image' === $f->type() => \is_array($raw) ? $raw : ['src' => \trim((string) $raw), 'alt' => ''],
            'link' === $f->type() => \is_array($raw) ? $raw : ['kind' => 'url', 'page' => '/', 'url' => \trim((string) $raw)],
            \in_array($f->type(), ['repeater', 'photos'], true) => \is_array($raw) ? $raw : [],
            default => \is_scalar($raw) ? (string) $raw : '',
        };
    }

    /** '7.00' (a decimal column) is 7, '12.50' is 12.5. */
    private function number(mixed $raw): int|float
    {
        $n = $raw + 0;
        if (!\is_finite((float) $n)) {
            throw ContentException::invalid(I18n::t('content.query_number_needed'));
        }

        return \is_float($n) && \floor($n) === $n && \abs($n) < 1e15 ? (int) $n : $n;
    }

    private function date(mixed $raw, string $format): string
    {
        if (!\is_scalar($raw) || '' === \trim((string) $raw) || \str_starts_with((string) $raw, '0000-00-00')) {
            return '';
        }
        $value = \trim((string) $raw);
        if (1 !== \preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z| ?[+-]\d{2}(?::?\d{2})?)?)?\z/', $value)) {
            return $value; // preserve invalid input for the shared field validator to report
        }
        try {
            $date = new \DateTimeImmutable($value);

            return false === \DateTimeImmutable::getLastErrors() ? $date->format($format) : $value;
        } catch (\Exception) {
            return $value;
        }
    }
}

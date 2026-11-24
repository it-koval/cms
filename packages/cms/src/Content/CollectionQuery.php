<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\QueryBuilder;
use Itk\Cms\Doctrine\JsonPath;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\FilterKind;
use Itk\Cms\Field\Number;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Resource\Metric;
use Itk\Cms\Schema\Collection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The database searches, filters and sorts the JSON translations. For top-level draft and published statuses, the row
 * determines whether there are unpublished changes. Those results are counted and paginated after the PHP status filter.
 *
 *   run($collection, ['q' => 'моск', 'filter' => ['status' => 'published', 'date' => ['from' => '2026-11-01']], 'sort' => '-date', 'page' => 2, 'perPage' => 50], 'ru')
 *   → { items, total, page, perPage, facets, metrics }
 *
 * `status` (draft | published) is a filter and a sort key of every collection, `updatedAt`, `createdAt` and `position` are sort keys.
 */
final class CollectionQuery
{
    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly CollectionItemRepository $items,
        private readonly CollectionService $collections,
        private readonly FieldAccess $access,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * @param array{q?: mixed, status?: mixed, onlyTranslated?: mixed, filter?: mixed, sort?: mixed, page?: mixed, perPage?: mixed} $params
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, facets: array<string, array<string, int>>, metrics: list<array<string, mixed>>}
     */
    public function run(Collection $c, array $params, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $this->path($locale, 'x'); // the language must be a plain code
        $perPage = \max(1, \min(self::MAX_PER_PAGE, (int) ($params['perPage'] ?? 0) ?: $c->perPageCount()));
        $page = \max(1, (int) ($params['page'] ?? 1));
        $filters = $this->filtersOf($c);
        $status = $params['status'] ?? '';
        if (!\in_array($status, ['', 'draft', 'published', 'missing'], true)) {
            throw ContentException::invalid(I18n::t('content.query_bad_status'));
        }

        $filterByRowStatus = 'draft' === $status || 'published' === $status;
        $qb = $this->base(
            $c,
            $locale,
            $filters,
            \is_array($params['filter'] ?? null) ? $params['filter'] : null,
            \trim((string) ($params['q'] ?? '')),
            $filterByRowStatus || \filter_var($params['onlyTranslated'] ?? false, \FILTER_VALIDATE_BOOLEAN),
            'missing' === $status,
        );
        $this->order($qb, $c, $locale, $this->sorts($c, (string) ($params['sort'] ?? '')));
        if (null !== ($initial = $this->collections->initialSingleton($c))) {
            $row = $this->collections->row($c, $initial, $locale);
            $matches = '' === $status || ('missing' === $status ? !$row['translated'] : $row['translated'] && 'draft' === $status);
            if (\filter_var($params['onlyTranslated'] ?? false, \FILTER_VALIDATE_BOOLEAN) && !$row['translated']) {
                $matches = false;
            }
            foreach (\is_array($params['filter'] ?? null) ? $params['filter'] : [] as $key => $value) {
                $matches = $this->initialMatches($filters[$key], $row[$key] ?? null, $value) && $matches;
            }
            $needle = \mb_strtolower(\trim((string) ($params['q'] ?? '')));
            if ('' !== $needle) {
                $keys = \is_array($params['filter'] ?? null) ? $c->searchKeys() : [$c->titleFieldKey(), $c->subFieldKey()];
                $haystack = \implode(' ', \array_map(static fn (?string $key): string => \is_scalar($row[$key ?? ''] ?? null) ? (string) $row[$key ?? ''] : '', $keys));
                $matches = $matches && \str_contains(\mb_strtolower($haystack), $needle);
            }
            $rows = $matches ? [$row] : [];

            return ['items' => 1 === $page ? $rows : [], 'total' => \count($rows), 'page' => $page, 'perPage' => $perPage, 'facets' => [], 'metrics' => $this->initialMetrics($c, $initial, $locale)];
        }
        $total = $filterByRowStatus ? null : (int) (clone $qb)->resetDQLPart('orderBy')->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $toRow = fn (CollectionItem $i): array => $this->collections->row($c, $i, $locale);
        $matchedRows = null;
        if (null === $total) {
            $matchedRows = [];
            foreach ($qb->getQuery()->getResult() as $item) {
                $row = $toRow($item);
                $isDraft = 'draft' === $row['status'] || $row['changed'];
                if ($row['translated'] && $isDraft === ('draft' === $status)) {
                    $matchedRows[] = $row;
                }
            }
            $total = \count($matchedRows);
        }

        $items = [];
        if ($total > 0 && $page - 1 <= \intdiv($total - 1, $perPage)) {
            $offset = ($page - 1) * $perPage;
            $items = null !== $matchedRows
                ? \array_slice($matchedRows, $offset, $perPage)
                : \array_map($toRow, $qb->setFirstResult($offset)->setMaxResults($perPage)->getQuery()->getResult());
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'facets' => $this->facets($c, $locale, $filters),
            'metrics' => $this->metrics($c, $locale),
        ];
    }

    /* ---------- filters ---------- */

    /** Match the one unpersisted initial row, after base() has validated the same filter inputs as a stored row. */
    private function initialMatches(FilterKind $kind, mixed $actual, mixed $value): bool
    {
        if (null === $value || '' === $value || [] === $value) {
            return true;
        }
        $text = \is_scalar($actual) ? (\is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual) : '';
        if (FilterKind::DateRange === $kind || FilterKind::NumberRange === $kind) {
            $range = \is_array($value) ? $value : ['from' => $value, 'to' => $value];
            $number = FilterKind::NumberRange === $kind;
            if ('' === $text || ($number && !\is_numeric($actual))) {
                return false;
            }
            $actual = $number ? $actual + 0 : \substr($text, 0, 10);

            return (!isset($range['from']) || '' === $range['from'] || $actual >= ($number ? $range['from'] + 0 : \substr((string) $range['from'], 0, 10)))
                && (!isset($range['to']) || '' === $range['to'] || $actual <= ($number ? $range['to'] + 0 : \substr((string) $range['to'], 0, 10)));
        }

        return match ($kind) {
            FilterKind::Text => \str_contains(\mb_strtolower($text), \mb_strtolower($this->scalar($value))),
            FilterKind::Select => $text === $this->scalar($value),
            FilterKind::Toggle => \filter_var($value, \FILTER_VALIDATE_BOOLEAN) ? 'true' === $text : '' === $text || 'false' === $text,
            FilterKind::Multi => \in_array($text, \is_array($value) ? $value : \array_map('trim', \explode(',', $this->scalar($value))), true),
            FilterKind::Relation => [] !== \array_intersect((array) $actual, (array) $value),
        };
    }

    /**
     * The filters the current person may use, by key: the collection's filter fields, and `status`.
     *
     * @return array<string, FilterKind>
     */
    private function filtersOf(Collection $c): array
    {
        $out = ['status' => FilterKind::Select];
        foreach ($this->access->visible($c->filterFields()) as $f) {
            $out[$f->key()] = $f->filterKind() ?? FilterKind::Text;
        }

        return $out;
    }

    /**
     * @param array<string, FilterKind> $kinds
     * @param array<string, mixed>|null $filter
     */
    private function base(Collection $c, string $locale, array $kinds, ?array $filter, string $q, bool $onlyTranslated, bool $missing): QueryBuilder
    {
        $qb = $this->items->createQueryBuilder('i')->where('i.collection = :collection')->setParameter('collection', $c->key());
        $version = "JSON_TEXT(i.translations, '" . JsonPath::of($locale) . "')";
        if ($onlyTranslated) {
            $qb->andWhere("$version IS NOT NULL");
        }
        if ($missing) {
            $qb->andWhere("$version IS NULL");
        }

        $n = 0;
        foreach ($filter ?? [] as $key => $value) {
            $key = (string) $key;
            if (!isset($kinds[$key])) {
                throw ContentException::invalid(I18n::t('content.query_filter_not_allowed'), ['filter' => $key]);
            }
            $this->apply($qb, $kinds[$key], $this->text($locale, $key), $this->numExpr($locale, $key), $value, 'f' . $n++);
        }
        if ('' !== $q) {
            $visible = \array_map(static fn (FieldInterface $f): string => $f->key(), $this->access->visible($c->fieldList()));
            $matches = null === $filter
                ? [$this->rowLabelMatch($c, $locale, $visible)]
                : \array_map(fn (string $key): string => 'LOWER(' . $this->text($locale, $key) . ') LIKE :q', \array_intersect($c->searchKeys(), $visible));
            if ([] === $matches) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere($qb->expr()->orX(...\array_values($matches)))->setParameter('q', '%' . $this->like(\mb_strtolower($q)) . '%');
            }
        }

        return $qb;
    }

    /** @param list<string> $visible */
    private function rowLabelMatch(Collection $c, string $locale, array $visible): string
    {
        // Use the same draft fallback as CollectionService::row() when the language is missing.
        $draft = 'COALESCE(' . $this->json($locale, 'draft') . ', ' . $this->json($this->mainLocale, 'draft')
            . ", JSON_GET(JSON_FIRST_VALUE(i.translations), '" . JsonPath::of('draft') . "'))";
        $value = function (?string $key) use ($locale, $draft, $visible): string {
            if (null === $key || !\in_array($key, $visible, true)) {
                return "''";
            }
            $this->path($locale, $key);

            return "COALESCE(JSON_TEXT($draft, '" . JsonPath::of($key) . "'), '')";
        };
        $title = $value($c->titleFieldKey());
        $subtitle = $value($c->subFieldKey());

        return "LOWER(CONCAT($title, ' ', $subtitle)) LIKE :q";
    }

    private function apply(QueryBuilder $qb, FilterKind $kind, string $text, string $num, mixed $value, string $p): void
    {
        if (null === $value || '' === $value || [] === $value) {
            return;
        }
        switch ($kind) {
            case FilterKind::Text:
                $qb->andWhere("LOWER($text) LIKE :$p")->setParameter($p, '%' . $this->like(\mb_strtolower($this->scalar($value))) . '%');
                break;
            case FilterKind::Select:
                $qb->andWhere("$text = :$p")->setParameter($p, $this->scalar($value));
                break;
            case FilterKind::Multi:
                $values = \is_array($value) ? \array_map(fn (mixed $v): string => $this->scalar($v), $value) : \array_map('trim', \explode(',', $this->scalar($value)));
                $qb->andWhere("$text IN (:$p)")->setParameter($p, \array_values(\array_filter($values, static fn (string $v): bool => '' !== $v)));
                break;
            case FilterKind::Toggle:
                $on = \filter_var($value, \FILTER_VALIDATE_BOOLEAN);
                $qb->andWhere($on ? "$text = 'true'" : "($text = 'false' OR $text IS NULL)");
                break;
            case FilterKind::DateRange:
                $range = \is_array($value) ? $value : ['from' => $value, 'to' => $value];
                foreach (['from' => '>=', 'to' => '<='] as $edge => $op) {
                    if (isset($range[$edge]) && '' !== $range[$edge]) {
                        if (!\preg_match('/^\d{4}-\d{2}-\d{2}/', $this->scalar($range[$edge]))) {
                            throw ContentException::invalid(I18n::t('content.query_date_format'));
                        }
                        $qb->andWhere("SUBSTRING($text, 1, 10) $op :{$p}_$edge")->setParameter("{$p}_$edge", \substr($this->scalar($range[$edge]), 0, 10));
                    }
                }
                break;
            case FilterKind::NumberRange:
                $range = \is_array($value) ? $value : ['from' => $value, 'to' => $value];
                foreach (['from' => '>=', 'to' => '<='] as $edge => $op) {
                    if (isset($range[$edge]) && '' !== $range[$edge]) {
                        if (!\is_numeric($range[$edge])) {
                            throw ContentException::invalid(I18n::t('content.query_number_needed'));
                        }
                        $qb->andWhere("$num $op :{$p}_$edge")->setParameter("{$p}_$edge", $range[$edge] + 0);
                    }
                }
                break;
            case FilterKind::Relation:
                $ids = \is_array($value) ? $value : [$value];
                $or = $qb->expr()->orX();
                foreach (\array_values($ids) as $k => $id) {
                    // a single relation holds the id, a multiple one a JSON list of ids: both are found as «"id"» inside the text
                    $or->add("($text = :{$p}_$k OR $text LIKE :{$p}_l$k)");
                    $qb->setParameter("{$p}_$k", $this->scalar($id))->setParameter("{$p}_l$k", '%"' . $this->like($this->scalar($id)) . '"%');
                }
                $qb->andWhere($or);
                break;
        }
    }

    /* ---------- sort ---------- */

    /**
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    private function sorts(Collection $c, string $sort): array
    {
        $allowed = ['status', 'updatedAt', 'createdAt', 'position'];
        foreach ($this->access->visible($c->fieldList()) as $f) {
            if ($f->isSortable()) {
                $allowed[] = $f->key();
            }
        }
        $out = [];
        if ('' !== \trim($sort)) {
            foreach (\explode(',', $sort) as $part) {
                $part = \trim($part);
                if ('' === $part) {
                    continue;
                }
                $key = \ltrim($part, '-+ ');
                if (!\in_array($key, $allowed, true)) {
                    throw ContentException::invalid(I18n::t('content.query_sort_not_allowed'), ['sort' => $key]);
                }
                $out[] = [$key, \str_starts_with($part, '-') ? 'DESC' : 'ASC'];
            }
        }
        if ([] === $out) {
            foreach ($c->defaultSortRule() as $key => $dir) {
                $out[] = [$key, 'desc' === $dir ? 'DESC' : 'ASC'];
            }
        }

        return $out;
    }

    /** @param list<array{0: string, 1: 'ASC'|'DESC'}> $sorts */
    private function order(QueryBuilder $qb, Collection $c, string $locale, array $sorts): void
    {
        $first = true;
        foreach ($sorts as [$key, $dir]) {
            $expr = match ($key) {
                'position' => 'i.position',
                'createdAt' => 'i.createdAt',
                default => $c->field($key) instanceof Number ? $this->numExpr($locale, $key) : $this->text($locale, $key),
            };
            $first ? $qb->orderBy($expr, $dir) : $qb->addOrderBy($expr, $dir);
            $first = false;
        }
        $first ? $qb->orderBy('i.position', 'ASC') : $qb->addOrderBy('i.position', 'ASC');
        $qb->addOrderBy('i.createdAt', 'ASC')->addOrderBy('i.id', 'ASC');
    }

    /* ---------- facets and metrics ---------- */

    /**
     * How many records have each value of the select, multi and toggle filters (whole collection), in the order the values first appear in the list.
     *
     * @param array<string, FilterKind> $kinds
     *
     * @return array<string, array<string, int>>
     */
    private function facets(Collection $c, string $locale, array $kinds): array
    {
        $out = [];
        foreach ($kinds as $key => $kind) {
            if (!\in_array($kind, [FilterKind::Select, FilterKind::Multi, FilterKind::Toggle], true)) {
                continue;
            }
            $expr = $this->text($locale, $key);
            $rows = $this->items->createQueryBuilder('i')
                ->select("$expr AS v, COUNT(i.id) AS n, MIN(i.position) AS HIDDEN first")
                ->where('i.collection = :collection')->setParameter('collection', $c->key())
                ->groupBy('v')->orderBy('first', 'ASC')
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                if (null === $row['v']) {
                    continue;
                }
                $out[$key][(string) $row['v']] = (int) $row['n'];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function metrics(Collection $c, string $locale): array
    {
        $out = [];
        foreach ($c->metricList() as $m) {
            $out[] = ['label' => $m->label, 'kind' => $m->kind, 'value' => $this->metric($c, $locale, $m)];
        }

        return $out;
    }

    /** The virtual row belongs to the same metric corpus as a stored singleton, independently of list filters. */
    private function initialMetrics(Collection $c, CollectionItem $initial, string $locale): array
    {
        $translation = $initial->translations[$locale] ?? [];
        $row = ['status' => $translation['status'] ?? null, 'updatedAt' => $translation['updatedAt'] ?? null] + ($translation['draft'] ?? []);
        $out = [];
        foreach ($c->metricList() as $m) {
            $matches = true;
            foreach ($m->filter as $key => $value) {
                $actual = $row[$key] ?? null;
                if (\is_bool($value)) {
                    $matches = $matches && ($value ? true === $actual : null === $actual || false === $actual);
                } elseif (\is_array($value) && !\array_is_list($value) && [] !== \array_intersect(['from', 'to'], \array_keys($value))) {
                    $matches = $matches && $this->initialMatches($c->field($key) instanceof Number ? FilterKind::NumberRange : FilterKind::DateRange, $actual, $value);
                } elseif (\is_array($value)) {
                    $matches = $matches && \in_array(\is_scalar($actual) ? $this->scalar($actual) : '', \array_map($this->scalar(...), \array_values($value)), true);
                } else {
                    $matches = $matches && null !== $actual && \is_scalar($actual) && $this->scalar($actual) === $this->scalar($value);
                }
            }
            $value = $matches ? ('sum' === $m->kind ? (\is_numeric($row[$m->field ?? ''] ?? null) ? $row[$m->field] + 0 : 0) : 1) : 0;
            $out[] = ['label' => $m->label, 'kind' => $m->kind, 'value' => $value];
        }

        return $out;
    }

    private function metric(Collection $c, string $locale, Metric $m): int|float
    {
        $qb = $this->items->createQueryBuilder('i')->where('i.collection = :collection')->setParameter('collection', $c->key());
        $n = 0;
        foreach ($m->filter as $key => $value) {
            $key = (string) $key;
            $text = $this->text($locale, $key);
            $p = 'm' . $n++;
            if (\is_bool($value)) {
                $this->apply($qb, FilterKind::Toggle, $text, $this->numExpr($locale, $key), $value ? '1' : '0', $p);
            } elseif (\is_array($value) && !\array_is_list($value) && [] !== \array_intersect(['from', 'to'], \array_keys($value))) {
                // a range, like the range filters of the list: ['dateStart' => ['from' => '2026-11-01']]
                $this->apply($qb, $c->field($key) instanceof Number ? FilterKind::NumberRange : FilterKind::DateRange, $text, $this->numExpr($locale, $key), $value, $p);
            } elseif (\is_array($value)) {
                $qb->andWhere("$text IN (:$p)")->setParameter($p, \array_map(fn (mixed $v): string => $this->scalar($v), \array_values($value)));
            } else {
                $qb->andWhere("$text = :$p")->setParameter($p, $this->scalar($value));
            }
        }
        if ('sum' === $m->kind) {
            $qb->select('SUM(' . $this->numExpr($locale, (string) $m->field) . ')');
            $sum = $qb->getQuery()->getSingleScalarResult();

            return null === $sum ? 0 : (\floor((float) $sum) === (float) $sum ? (int) $sum : (float) $sum);
        }

        return (int) $qb->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
    }

    /* ---------- JSON expressions ---------- */

    private function json(string ...$keys): string
    {
        return "JSON_GET(i.translations, '" . JsonPath::of(...$keys) . "')";
    }

    /** JSON_TEXT(…) of a field of the draft (`status` and `updatedAt` are beside the draft). */
    private function text(string $locale, string $key): string
    {
        return "JSON_TEXT(i.translations, '" . $this->path($locale, $key) . "')";
    }

    private function numExpr(string $locale, string $key): string
    {
        return "JSON_NUMBER(i.translations, '" . $this->path($locale, $key) . "')";
    }

    /** The JSON path of a value; language and key are plain words, so the path can be written into the query. */
    private function path(string $locale, string $key): string
    {
        if (!\preg_match('/^[A-Za-z0-9_\-]+$/', $locale) || !\preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
            throw ContentException::invalid(I18n::t('content.query_bad_field_or_language'));
        }

        return \in_array($key, ['status', 'updatedAt'], true) ? JsonPath::of($locale, $key) : JsonPath::of($locale, 'draft', $key);
    }

    private function like(string $value): string
    {
        return \addcslashes($value, '%_\\');
    }

    private function scalar(mixed $value): string
    {
        if (!\is_scalar($value)) {
            throw ContentException::invalid(I18n::t('content.query_bad_filter_value'));
        }

        return \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}

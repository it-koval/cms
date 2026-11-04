<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\QueryBuilder;
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
 * The list of a collection with search, filters, sorting and pages — done by the database: the fields live inside the JSON column
 * `translations`, and JSON_UNQUOTE(JSON_EXTRACT(i.translations, '$."ru".draft."title"')) (DQL functions of the bundle) reaches them.
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
     * @param array{q?: mixed, filter?: mixed, sort?: mixed, page?: mixed, perPage?: mixed} $params
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

        $qb = $this->base($c, $locale, $filters, \is_array($params['filter'] ?? null) ? $params['filter'] : [], \trim((string) ($params['q'] ?? '')));
        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $this->order($qb, $c, $locale, $this->sorts($c, (string) ($params['sort'] ?? '')));
        $rows = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return [
            'items' => \array_map(fn (CollectionItem $i): array => $this->collections->row($c, $i, $locale), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'facets' => $this->facets($c, $locale, $filters),
            'metrics' => $this->metrics($c, $locale),
        ];
    }

    /* ---------- filters ---------- */

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
     * @param array<string, mixed> $filter
     */
    private function base(Collection $c, string $locale, array $kinds, array $filter, string $q): QueryBuilder
    {
        $qb = $this->items->createQueryBuilder('i')->where('i.collection = :collection')->setParameter('collection', $c->key());
        $n = 0;
        foreach ($filter as $key => $value) {
            $key = (string) $key;
            if (!isset($kinds[$key])) {
                throw ContentException::invalid(I18n::t('content.query_filter_not_allowed'), ['filter' => $key]);
            }
            $this->apply($qb, $kinds[$key], $this->text($locale, $key), $this->numExpr($locale, $key), $value, 'f' . $n++);
        }
        if ('' !== $q) {
            $or = $qb->expr()->orX();
            $visible = \array_map(static fn (FieldInterface $f): string => $f->key(), $this->access->visible($c->fieldList()));
            foreach ($c->searchKeys() as $key) {
                if (\in_array($key, $visible, true)) {
                    $or->add('LOWER(' . $this->text($locale, $key) . ') LIKE :q');
                }
            }
            if (0 === $or->count()) {
                $or->add('1 = 0');
            }
            $qb->andWhere($or)->setParameter('q', '%' . $this->like(\mb_strtolower($q)) . '%');
        }

        return $qb;
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
     * How many records have each value of the select, multi and toggle filters (whole collection).
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
                ->select("$expr AS v, COUNT(i.id) AS n")
                ->where('i.collection = :collection')->setParameter('collection', $c->key())
                ->groupBy('v')
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

    /** JSON_UNQUOTE(JSON_EXTRACT(…)) of a field of the draft (`status` and `updatedAt` are beside the draft). */
    private function text(string $locale, string $key): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(i.translations, '" . $this->path($locale, $key) . "'))";
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

        return \in_array($key, ['status', 'updatedAt'], true) ? "$.\"$locale\".\"$key\"" : "$.\"$locale\".draft.\"$key\"";
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

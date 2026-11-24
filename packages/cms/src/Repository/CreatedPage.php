<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\ORM\QueryBuilder;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;

/**
 * A page of a filtered list ordered by creation time and id, newest first.
 *
 * @internal
 */
final class CreatedPage
{
    /**
     * @template T of MediaFile|Request
     *
     * @param class-string<T> $entity
     *
     * @return array{items: list<T>, total: int, nextCursor: ?string, previousCursor: ?string}
     */
    public static function read(QueryBuilder $query, string $entity, int $offset, int $limit, string $cursor): array
    {
        $alias = $query->getRootAliases()[0];
        $anchor = '' === $cursor ? null : self::decode($cursor, Request::class === $entity);
        $total = (int) (clone $query)->select('COUNT(' . $alias . '.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = [];
        if (null === $anchor) {
            if ($offset < $total) {
                $items = $query->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();
            }
            $hasNewer = $offset > 0;
            $hasOlder = $limit < $total - $offset;
        } else {
            [$direction, $time, $id] = $anchor;
            $newer = 'newer' === $direction;
            $window = self::past(clone $query, $alias, $time, $id, $newer);
            $order = $newer ? 'ASC' : 'DESC';
            $items = $window->orderBy($alias . '.createdAt', $order)->addOrderBy($alias . '.id', $order)->setMaxResults($limit)->getQuery()->getResult();
            if ($newer) {
                $items = \array_reverse($items);
            }
            $hasNewer = [] !== $items && self::hasRows($query, $alias, $items[0], true);
            $hasOlder = [] !== $items && self::hasRows($query, $alias, $items[\array_key_last($items)], false);
        }

        return [
            'items' => $items,
            'total' => $total,
            'nextCursor' => $hasOlder && [] !== $items ? self::encode('older', $items[\array_key_last($items)]) : null,
            'previousCursor' => $hasNewer && [] !== $items ? self::encode('newer', $items[0]) : null,
        ];
    }

    private static function encode(string $direction, MediaFile|Request $row): string
    {
        return $direction . '/' . $row->createdAt->format('Y-m-d H:i:s') . '/' . $row->id;
    }

    /** @return array{string, \DateTimeImmutable, int|string} */
    private static function decode(string $cursor, bool $numeric): array
    {
        if (\strlen($cursor) > 64 || \str_contains($cursor, "\0")) {
            throw ContentException::invalid(I18n::t('http.request_unreadable'));
        }
        [$direction, $stamp, $key] = \explode('/', $cursor, 3) + ['', '', ''];
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp);
        $id = $numeric ? \filter_var($key, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2_147_483_647]]) : $key;
        if (!\in_array($direction, ['older', 'newer'], true) || false === $time || $time->format('Y-m-d H:i:s') !== $stamp || false === $id || '' === $id || \strlen($key) > 36) {
            throw ContentException::invalid(I18n::t('http.request_unreadable'));
        }

        return [$direction, $time, $id];
    }

    private static function past(QueryBuilder $query, string $alias, \DateTimeImmutable $time, int|string $id, bool $newer): QueryBuilder
    {
        $operator = $newer ? '>' : '<';

        return $query->andWhere(\sprintf('(%1$s.createdAt %2$s :cursorTime OR (%1$s.createdAt = :cursorTime AND %1$s.id %2$s :cursorId))', $alias, $operator))
            ->setParameter('cursorTime', $time)->setParameter('cursorId', $id);
    }

    private static function hasRows(QueryBuilder $query, string $alias, MediaFile|Request $row, bool $newer): bool
    {
        return [] !== self::past(clone $query, $alias, $row->createdAt, $row->id ?? 0, $newer)
            ->select($alias . '.id')->resetDQLPart('orderBy')->setMaxResults(1)->getQuery()->getScalarResult();
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\Revision;

/**
 * @extends ServiceEntityRepository<Revision>
 *
 * @internal
 */
class RevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Revision::class);
    }

    /** The newest entry of one version of an object. */
    public function latest(string $kind, string $id, string $locale, ?string $resourceKey = null): ?Revision
    {
        return $this->forObject($kind, $id, $locale, $resourceKey)
            ->orderBy('r.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** The newest entry that changed the draft of one version (publishing and unpublishing leave the draft as it is). */
    public function latestChange(string $kind, string $id, string $locale): ?Revision
    {
        return $this->createQueryBuilder('r')
            ->where('r.kind = :k AND r.resourceId = :i AND r.locale = :l AND r.action NOT IN (:same)')
            ->setParameter('k', $kind)->setParameter('i', $id)->setParameter('l', $locale)->setParameter('same', [Revision::PUBLISH, Revision::UNPUBLISH])
            ->orderBy('r.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** The entry right before `$r` (same object and language). */
    public function before(Revision $r): ?Revision
    {
        $key = Revision::SETTINGS === $r->kind && 'notifications' === $r->settingsGroup() ? $r->resourceKey : null;

        return $this->forObject($r->kind, $r->resourceId, $r->locale, $key)
            ->andWhere('r.id < :id')->setParameter('id', $r->id)
            ->orderBy('r.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return list<Revision> newest first */
    public function history(string $kind, string $id, ?string $locale, int $limit, int $offset = 0, ?string $resourceKey = null): array
    {
        return $this->forObject($kind, $id, $locale, $resourceKey)
            ->orderBy('r.id', 'DESC')->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();
    }

    public function countOf(string $kind, string $id, ?string $locale, ?string $resourceKey = null): int
    {
        return (int) $this->forObject($kind, $id, $locale, $resourceKey)
            ->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return array<string, int> The current revision id of each language, independent of the requested history page. */
    public function latestIds(string $kind, string $id, ?string $resourceKey = null): array
    {
        $rows = $this->forObject($kind, $id, null, $resourceKey)->select('r.locale AS locale, MAX(r.id) AS id')
            ->groupBy('r.locale')->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['locale']] = (int) $row['id'];
        }

        return $out;
    }

    private function forObject(string $kind, string $id, ?string $locale, ?string $resourceKey): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.kind = :k AND r.resourceId = :i')
            ->setParameter('k', $kind)->setParameter('i', $id);
        if (null !== $locale) {
            $qb->andWhere('r.locale = :l')->setParameter('l', $locale);
        }
        if (null !== $resourceKey) {
            $qb->andWhere('r.resourceKey = :key')->setParameter('key', $resourceKey);
        }

        return $qb;
    }

    /** Is there anything recorded for the object, in any language? */
    public function has(string $kind, string $id): bool
    {
        return null !== $this->createQueryBuilder('r')->select('r.id')
            ->where('r.kind = :k AND r.resourceId = :i')->setParameter('k', $kind)->setParameter('i', $id)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** The newest entry of an object in any language (its template or collection key survives the object). */
    public function lastOf(string $kind, string $id): ?Revision
    {
        return $this->createQueryBuilder('r')
            ->where('r.kind = :k AND r.resourceId = :i')->setParameter('k', $kind)->setParameter('i', $id)
            ->orderBy('r.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * The trash: deleted pages and records that nobody has restored (no entry after the deletion), newest first.
     *
     * @param list<string>|null $kinds
     *
     * @return list<Revision>
     */
    public function trash(?array $kinds = null, ?string $key = null, int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.action = :del')->setParameter('del', Revision::DELETE)
            ->andWhere('NOT EXISTS (SELECT n.id FROM ' . Revision::class . ' n WHERE n.kind = r.kind AND n.resourceId = r.resourceId AND n.id > r.id)')
            ->orderBy('r.id', 'DESC')->setMaxResults($limit);
        if (null !== $kinds) {
            $qb->andWhere('r.kind IN (:kinds)')->setParameter('kinds', $kinds);
        }
        if (null !== $key && '' !== $key) {
            $qb->andWhere('r.resourceKey = :key')->setParameter('key', $key);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Ids of the entries of an object beyond the newest `$keep` (the ones to drop).
     *
     * @return list<int>
     */
    public function beyond(string $kind, string $id, string $locale, int $keep, int $limit = 1000): array
    {
        $rows = $this->createQueryBuilder('r')->select('r.id')
            ->where('r.kind = :k AND r.resourceId = :i AND r.locale = :l')
            ->setParameter('k', $kind)->setParameter('i', $id)->setParameter('l', $locale)
            ->orderBy('r.id', 'DESC')->setFirstResult(\max(0, $keep))->setMaxResults($limit)->getQuery()->getScalarResult();

        return \array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Objects that have more than `$keep` entries.
     *
     * @return list<array{kind: string, id: string, locale: string}>
     */
    public function overfull(int $keep): array
    {
        $rows = $this->createQueryBuilder('r')->select('r.kind AS kind, r.resourceId AS id, r.locale AS locale')
            ->groupBy('r.kind, r.resourceId, r.locale')->having('COUNT(r.id) > :keep')->setParameter('keep', $keep)
            ->getQuery()->getArrayResult();

        return \array_values($rows);
    }

    /**
     * Ids of the entries not touched since `$cutoff`, except the newest entry of an object that still exists (a deletion has no such protection).
     *
     * @return list<int>
     */
    public function staleIds(\DateTimeImmutable $cutoff, int $limit = 5000): array
    {
        $rows = $this->createQueryBuilder('r')->select('r.id')
            ->where('r.updatedAt < :cut')->setParameter('cut', $cutoff)
            ->andWhere('(r.action = :del OR r.id NOT IN (SELECT MAX(m.id) FROM ' . Revision::class . ' m GROUP BY m.kind, m.resourceId, m.locale))')
            ->setParameter('del', Revision::DELETE)
            ->setMaxResults($limit)->getQuery()->getScalarResult();

        return \array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @param list<int> $ids */
    public function deleteIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')->delete()->where('r.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
    }
}

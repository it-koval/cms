<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\Page;

/**
 * @extends ServiceEntityRepository<Page>
 *
 * @internal
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    /** @return list<Page> every page with its parent and its versions (one query: the callers read the versions of each page), parents before children where positions allow, then by position */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')->leftJoin('p.parent', 'pp')->addSelect('pp')->leftJoin('p.translations', 't')->addSelect('t')
            ->orderBy('p.position', 'ASC')->addOrderBy('p.createdAt', 'ASC')->getQuery()->getResult();
    }

    /** @return array<string, string|null> page id => parent id for every page, one scalar query (nothing is hydrated): for walking up a tree without a query per level */
    public function parentIds(): array
    {
        $map = [];
        foreach ($this->createQueryBuilder('p')->select('p.id AS id', 'IDENTITY(p.parent) AS parent')->getQuery()->getArrayResult() as $row) {
            $map[(string) $row['id']] = null === $row['parent'] ? null : (string) $row['parent'];
        }

        return $map;
    }

    /** Lightweight subtree metadata; draft-only ancestors remain traversable, unrelated branches are never loaded.
     * @return list<array{id: string, parent_id: string, template: string}>
     */
    public function descendants(string $id): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'WITH RECURSIVE descendants AS (
                SELECT id, parent_id, template, position, created_at FROM itk_page WHERE parent_id = :parent
                UNION
                SELECT p.id, p.parent_id, p.template, p.position, p.created_at
                FROM itk_page p INNER JOIN descendants d ON p.parent_id = d.id
            ) SELECT id, parent_id, template FROM descendants ORDER BY position ASC, created_at ASC',
            ['parent' => $id],
        );
    }

    public function nextPosition(?Page $parent): int
    {
        $qb = $this->createQueryBuilder('p')->select('MAX(p.position)');
        if (null === $parent) {
            $qb->where('p.parent IS NULL');
        } else {
            $qb->where('p.parent = :parent')->setParameter('parent', $parent);
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    public function countChildren(Page $page): int
    {
        return (int) $this->createQueryBuilder('p')->select('COUNT(p.id)')->where('p.parent = :page')->setParameter('page', $page)->getQuery()->getSingleScalarResult();
    }
}

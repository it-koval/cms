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

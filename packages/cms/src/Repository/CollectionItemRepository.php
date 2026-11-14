<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\CollectionItem;

/**
 * @extends ServiceEntityRepository<CollectionItem>
 *
 * @internal
 */
class CollectionItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionItem::class);
    }

    /** @return list<CollectionItem> */
    public function findByCollection(string $collection): array
    {
        return $this->createQueryBuilder('i')->where('i.collection = :c')->setParameter('c', $collection)->orderBy('i.position', 'ASC')->addOrderBy('i.createdAt', 'ASC')->getQuery()->getResult();
    }

    public function nextPosition(string $collection): int
    {
        $max = $this->createQueryBuilder('i')->select('MAX(i.position)')->where('i.collection = :c')->setParameter('c', $collection)->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }

    /** The records nested in `$parentId` become records of `$newParentId` (null = the top level); the entities are changed, so a mass removal sees the new parents. */
    public function moveChildren(string $collection, string $parentId, ?string $newParentId): void
    {
        foreach ($this->findBy(['collection' => $collection, 'parentId' => $parentId]) as $child) {
            $child->parentId = $newParentId;
        }
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\MediaFolder;

/**
 * @extends ServiceEntityRepository<MediaFolder>
 *
 * @internal
 */
class MediaFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaFolder::class);
    }

    /** @return list<MediaFolder> every folder, in tree order inside each parent */
    public function all(): array
    {
        return $this->createQueryBuilder('f')->orderBy('f.position', 'ASC')->addOrderBy('f.name', 'ASC')->getQuery()->getResult();
    }
}

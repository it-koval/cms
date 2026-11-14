<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\Redirect;

/**
 * @extends ServiceEntityRepository<Redirect>
 *
 * @internal
 */
class RedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Redirect::class);
    }

    public function findByFrom(string $from): ?Redirect
    {
        return $this->findOneBy(['fromPath' => $from]);
    }
}

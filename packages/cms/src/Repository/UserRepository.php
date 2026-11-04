<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\User;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @internal
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => \mb_strtolower(\trim($email))]);
    }

    /** Active administrators: somebody has to stay who can manage users and roles. */
    public function countActiveWithRole(string $role): int
    {
        return (int) $this->createQueryBuilder('u')->select('COUNT(u.id)')->where('u.role = :r AND u.status = :s')
            ->setParameter('r', $role)->setParameter('s', User::ACTIVE)->getQuery()->getSingleScalarResult();
    }
}

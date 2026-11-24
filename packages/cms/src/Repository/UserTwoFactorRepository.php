<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\User;
use Itk\Cms\Entity\UserTwoFactor;

/**
 * @extends ServiceEntityRepository<UserTwoFactor>
 *
 * @internal
 */
class UserTwoFactorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTwoFactor::class);
    }

    public function ofUser(User $user): ?UserTwoFactor
    {
        return $this->findOneBy(['user' => $user]);
    }

    /** @return iterable<string> sealed secrets of accounts with two-step sign-in enabled */
    public function enabledSecrets(): iterable
    {
        foreach ($this->createQueryBuilder('f')->select('f.secret')->where('f.enabledAt IS NOT NULL')->getQuery()->toIterable() as $row) {
            yield $row['secret'];
        }
    }
}

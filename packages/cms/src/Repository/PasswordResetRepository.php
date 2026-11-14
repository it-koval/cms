<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\PasswordReset;

/**
 * @extends ServiceEntityRepository<PasswordReset>
 *
 * @internal
 */
class PasswordResetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordReset::class);
    }

    public function findByHash(string $hash): ?PasswordReset
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** Takes every unused link of a person out of use (after he set a password). @return int how many */
    public function burnAllOf(string $userId, \DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQuery('UPDATE Itk\Cms\Entity\PasswordReset p SET p.usedAt = :now WHERE p.user = :u AND p.usedAt IS NULL')
            ->setParameter('now', $now)->setParameter('u', $userId)->execute();
    }

    /** Forgets links that expired or were used more than a week ago. */
    public function purge(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->createQuery('DELETE FROM Itk\Cms\Entity\PasswordReset p WHERE p.expiresAt < :old OR (p.usedAt IS NOT NULL AND p.usedAt < :old)')
            ->setParameter('old', $now->modify('-7 days'))->execute();
    }
}

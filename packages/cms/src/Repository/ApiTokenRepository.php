<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\ApiToken;

/**
 * @extends ServiceEntityRepository<ApiToken>
 *
 * @internal
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findByHash(string $hash): ?ApiToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** @return list<ApiToken> newest first */
    public function ofUser(string $userId): array
    {
        return $this->createQueryBuilder('t')->where('IDENTITY(t.user) = :u')->setParameter('u', $userId)->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();
    }

    /** @return list<ApiToken> newest first */
    public function everyone(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();
    }

    /** How many keys of the person work now (not revoked, not expired). */
    public function countActive(string $userId, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->where('IDENTITY(t.user) = :u AND t.revokedAt IS NULL AND (t.expiresAt IS NULL OR t.expiresAt > :now)')
            ->setParameter('u', $userId)->setParameter('now', $now)->getQuery()->getSingleScalarResult();
    }
}

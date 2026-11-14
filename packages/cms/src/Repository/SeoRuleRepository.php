<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\SeoRule;

/**
 * @extends ServiceEntityRepository<SeoRule>
 *
 * @internal
 */
class SeoRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoRule::class);
    }

    /** @return list<SeoRule> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true]);
    }

    /** @return list<SeoRule> newest first */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    /** The rule with the same address, match and language (for the duplicate check). */
    public function findSame(string $path, string $match, ?string $locale): ?SeoRule
    {
        return $this->findOneBy(['path' => $path, 'matchType' => $match, 'locale' => $locale]);
    }
}

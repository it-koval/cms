<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\PageTranslation;

/**
 * @extends ServiceEntityRepository<PageTranslation>
 *
 * @internal
 */
class PageTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageTranslation::class);
    }

    /** The live page at an address, or null. */
    public function findLive(string $locale, string $url): ?PageTranslation
    {
        return $this->createQueryBuilder('t')
            ->addSelect('p')->join('t.page', 'p')
            ->where('t.locale = :locale AND t.publishedUrl = :url AND t.status = :status')
            ->setParameter('locale', $locale)->setParameter('url', $url)->setParameter('status', PageTranslation::PUBLISHED)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<PageTranslation> all versions of a language with their pages */
    public function findForLocale(string $locale): array
    {
        return $this->createQueryBuilder('t')->addSelect('p')->join('t.page', 'p')->where('t.locale = :locale')->setParameter('locale', $locale)->getQuery()->getResult();
    }

    /** @return list<PageTranslation> live pages of a language */
    public function findLiveForLocale(string $locale): array
    {
        return $this->createQueryBuilder('t')->addSelect('p')->join('t.page', 'p')
            ->where('t.locale = :locale AND t.status = :status')->setParameter('locale', $locale)->setParameter('status', PageTranslation::PUBLISHED)
            ->orderBy('p.position', 'ASC')->getQuery()->getResult();
    }

    /**
     * The addresses of the live pages of a language without the pages themselves: nothing is hydrated, the title and the address come out of the JSON by the database.
     *
     * @return list<array{id: string, key: string, title: string, url: string}> `key` is the published address as it is looked up (PageTranslation::$publishedUrl), `url` the one in the snapshot
     */
    public function findLiveAddresses(string $locale): array
    {
        $rows = $this->createQueryBuilder('t')->join('t.page', 'p')
            ->select('IDENTITY(t.page) AS id', 't.publishedUrl AS key', "JSON_TEXT(t.published, '{\"title\"}') AS title", "JSON_TEXT(t.published, '{\"url\"}') AS url")
            ->where('t.locale = :locale AND t.status = :status AND t.published IS NOT NULL')->setParameter('locale', $locale)->setParameter('status', PageTranslation::PUBLISHED)
            ->orderBy('p.position', 'ASC')->getQuery()->getArrayResult();

        return \array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'key' => (string) $r['key'], 'title' => (string) ($r['title'] ?? ''), 'url' => (string) ($r['url'] ?? '')], $rows);
    }

    /** @return list<PageTranslation> live pages of every language (the sitemap) */
    public function findAllLive(): array
    {
        return $this->createQueryBuilder('t')->addSelect('p')->join('t.page', 'p')
            ->where('t.status = :status AND t.publishedUrl IS NOT NULL')->setParameter('status', PageTranslation::PUBLISHED)
            ->orderBy('t.locale', 'ASC')->addOrderBy('p.position', 'ASC')->getQuery()->getResult();
    }
}

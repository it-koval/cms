<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Support\Url;

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

    /**
     * @param list<string> $locales configured locales, or [] to include legacy versions
     * @param list<string>|null $templates templates visible to the editor, or null for unrestricted access
     *
     * @return iterable<array{id: string, pageId: string, template: string, locale: string, status: string, draft: array<string, mixed>, published: ?array<string, mixed>, migrated: bool, awaiting: ?array<string, mixed>, updatedAt: \DateTimeImmutable, publishedAt: ?\DateTimeImmutable}>
     */
    public function forOverview(array $locales, ?array $templates): iterable
    {
        if ([] === $templates) {
            return;
        }
        $query = $this->createQueryBuilder('t')->join('t.page', 'p')
            ->select('t.id AS id', 'p.id AS pageId', 'p.template AS template', 't.locale AS locale', 't.status AS status', 't.draft AS draft', 't.published AS published', 't.migrated AS migrated', 't.awaiting AS awaiting', 't.updatedAt AS updatedAt', 't.publishedAt AS publishedAt')
            ->where('t.id > :cursor')->orderBy('t.id', 'ASC')->setMaxResults(100);
        if ([] !== $locales) {
            $query->andWhere('t.locale IN (:locales)')->setParameter('locales', $locales);
        }
        if (null !== $templates) {
            $query->andWhere('p.template IN (:templates)')->setParameter('templates', $templates);
        }
        $cursor = '';
        do {
            $batch = $query->setParameter('cursor', $cursor)->getQuery()->getArrayResult();
            foreach ($batch as $row) {
                $cursor = $row['id'];
                yield $row;
            }
        } while (100 === \count($batch));
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

    public function findLiveAtPath(string $locale, string $path, bool $prefixed): ?PageTranslation
    {
        $key = Url::key($path);
        $page = $this->findLive($locale, $key);
        if (null !== $page || !$prefixed || !\str_contains($key, '//')) {
            return $page;
        }

        // Keep a published address with doubled slashes as stored. Only try the other spelling after a miss.
        return $this->findLive($locale, \preg_replace('~/+~', '/', $key) ?? $key);
    }

    /** Other live versions which routing may resolve at one old address (at most two keys).
     *
     * @param list<string> $keys
     *
     * @return list<PageTranslation>
     */
    public function findOtherLiveAtKeys(string $locale, array $keys, string $exceptId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.locale = :locale AND t.publishedUrl IN (:keys) AND t.id != :except AND t.status = :status AND t.published IS NOT NULL')
            ->setParameter('locale', $locale)->setParameter('keys', $keys)->setParameter('except', $exceptId)
            ->setParameter('status', PageTranslation::PUBLISHED)->getQuery()->getResult();
    }

    /** @return list<PageTranslation> all versions of a language with their pages */
    public function findForLocale(string $locale): array
    {
        return $this->createQueryBuilder('t')->addSelect('p')->join('t.page', 'p')->where('t.locale = :locale')->setParameter('locale', $locale)->getQuery()->getResult();
    }

    /** @return list<array{pageId: string, draftUrl: ?string, publishedUrl: ?string}> fresh addresses without ORM identity-map caching */
    public function findAddresses(string $locale): array
    {
        return $this->createQueryBuilder('t')
            ->select('IDENTITY(t.page) AS pageId', "JSON_TEXT(t.draft, '{\"url\"}') AS draftUrl", 't.publishedUrl AS publishedUrl')
            ->where('t.locale = :locale')->setParameter('locale', $locale)->getQuery()->getArrayResult();
    }

    /** @return list<PageTranslation> live pages of a language */
    public function findLiveForLocale(string $locale): array
    {
        return $this->createQueryBuilder('t')->addSelect('p')->join('t.page', 'p')
            ->where('t.locale = :locale AND t.status = :status')->setParameter('locale', $locale)->setParameter('status', PageTranslation::PUBLISHED)
            ->orderBy('p.position', 'ASC')->getQuery()->getResult();
    }

    /**
     * Only snapshots needed by a public subtree listing, without entity hydration.
     *
     * @param list<string> $pageIds
     *
     * @return array<string, array<string, mixed>> page id => published snapshot
     */
    public function findLiveSnapshotsForPages(string $locale, array $pageIds): array
    {
        if ([] === $pageIds) {
            return [];
        }
        $out = [];
        // PostgreSQL accepts at most 65535 bound parameters. The ids may include a large draft subtree.
        foreach (\array_chunk($pageIds, 1000) as $ids) {
            $rows = $this->createQueryBuilder('t')
                ->select('IDENTITY(t.page) AS pageId', 't.published AS published')
                ->where('t.locale = :locale AND t.status = :status AND t.published IS NOT NULL AND t.page IN (:ids)')
                ->setParameter('locale', $locale)->setParameter('status', PageTranslation::PUBLISHED)->setParameter('ids', $ids)
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $out[(string) $row['pageId']] = (array) $row['published'];
            }
        }

        return $out;
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

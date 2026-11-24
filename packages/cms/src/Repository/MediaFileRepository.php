<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Entity\MediaFile;

/**
 * @extends ServiceEntityRepository<MediaFile>
 *
 * @internal
 */
class MediaFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaFile::class);
    }

    /**
     * @param string $folder '' every file, MediaFile::NO_FOLDER the files outside any folder, else the id of a folder (the files directly in it)
     *
     * @return list<MediaFile> newest first
     */
    public function search(string $q = '', string $type = '', string $folder = ''): array
    {
        return $this->searchQuery($q, $type, $folder)->getQuery()->getResult();
    }

    /** @return array{items: list<MediaFile>, total: int} */
    public function page(string $q, string $type, string $folder, int $offset, int $limit): array
    {
        $query = $this->searchQuery($q, $type, $folder);
        $total = (int) (clone $query)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $offset < $total ? $query->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult() : [];

        return ['items' => $items, 'total' => $total];
    }

    /** @return iterable<array{path: string, width: int}> */
    public function imagesForWarming(): iterable
    {
        $cursor = '';
        do {
            $batch = $this->createQueryBuilder('m')
                ->select('m.id', 'm.path', 'm.width')
                ->where("m.mime LIKE 'image/%'")->andWhere('m.id > :cursor')->setParameter('cursor', $cursor)
                ->orderBy('m.id', 'ASC')->setMaxResults(250)
                ->getQuery()->getArrayResult();
            foreach ($batch as $row) {
                $cursor = $row['id'];
                yield ['path' => $row['path'], 'width' => $row['width']];
            }
        } while (250 === \count($batch));
    }

    private function searchQuery(string $q, string $type, string $folder): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC');
        $q = \mb_strtolower(\trim($q));
        if ('' !== $q) {
            $needle = \strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $qb->andWhere("LOWER(m.title) LIKE :q ESCAPE '!' OR LOWER(m.originalName) LIKE :q ESCAPE '!' OR LOWER(m.alt) LIKE :q ESCAPE '!'")
                ->setParameter('q', '%' . $needle . '%');
        }
        if ('img' === $type) {
            $qb->andWhere('m.mime LIKE :t')->setParameter('t', 'image/%');
        } elseif ('pdf' === $type) {
            $qb->andWhere('m.mime = :t')->setParameter('t', 'application/pdf');
        }
        if (MediaFile::NO_FOLDER === $folder) {
            $qb->andWhere('m.folder IS NULL');
        } elseif ('' !== $folder) {
            $qb->andWhere('IDENTITY(m.folder) = :folder')->setParameter('folder', $folder);
        }

        return $qb;
    }
}

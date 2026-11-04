<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        $qb = $this->createQueryBuilder('m')->orderBy('m.createdAt', 'DESC');
        if ('' !== \trim($q)) {
            $qb->andWhere('LOWER(m.title) LIKE :q OR LOWER(m.originalName) LIKE :q OR LOWER(m.alt) LIKE :q')->setParameter('q', '%' . \mb_strtolower(\trim($q)) . '%');
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

        return $qb->getQuery()->getResult();
    }
}

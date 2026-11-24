<?php

declare(strict_types=1);

namespace Itk\Cms\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;

/**
 * @extends ServiceEntityRepository<Request>
 *
 * @internal
 */
class RequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Request::class);
    }

    /**
     * @param array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string} $filter
     *
     * @return list<Request> newest first
     */
    public function search(array $filter = [], ?int $limit = null): array
    {
        return $this->searchQuery($filter)->setMaxResults($limit)->getQuery()->getResult();
    }

    /**
     * @param array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string} $filter
     *
     * @return array{items: list<Request>, total: int, nextCursor: ?string, previousCursor: ?string}
     */
    public function page(array $filter, int $offset, int $limit, string $cursor = ''): array
    {
        return CreatedPage::read($this->searchQuery($filter), Request::class, $offset, $limit, $cursor);
    }

    /** @param array{status?: string, form?: string, q?: string, period?: string, from?: string, to?: string, locale?: string} $filter */
    private function searchQuery(array $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC');
        $status = $filter['status'] ?? '';
        if ('failed' === $status) {
            $qb->andWhere('r.delivery = :failed')->setParameter('failed', Request::DELIVERY_FAILED);
        } elseif ('' !== $status) {
            $qb->andWhere('r.work = :work')->setParameter('work', $status);
        }
        if (!empty($filter['form'])) {
            $qb->andWhere('r.form = :form')->setParameter('form', $filter['form']);
        }
        if (!empty($filter['locale'])) {
            $qb->andWhere('r.locale = :loc')->setParameter('loc', $filter['locale']);
        }
        $from = null;
        $to = null;
        $today = new \DateTimeImmutable('today');
        switch ($filter['period'] ?? '') {
            case 'today': $from = $today;
                break;
            case '7': $from = $today->modify('-6 days');
                break;
            case '30': $from = $today->modify('-29 days');
                break;
            case 'custom':
                $from = self::filterDate($filter['from'] ?? '', 'from');
                $to = self::filterDate($filter['to'] ?? '', 'to');
                if (null !== $from && null !== $to && $from > $to) {
                    $message = I18n::t('http.request_date_range_invalid');
                    throw ContentException::invalid($message, ['to' => $message]);
                }
                $to = $to?->modify('+1 day');
                break;
        }
        if (null !== $from) {
            $qb->andWhere('r.createdAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('r.createdAt < :to')->setParameter('to', $to);
        }
        $q = \trim((string) ($filter['q'] ?? ''));
        if ('' !== $q) {
            $digits = \preg_replace('/[^0-9]+/', '', $q) ?? '';
            if ('' !== $digits) {
                $qb->andWhere('ITK_PHONE_DIGITS(r.phone) LIKE :digits')->setParameter('digits', '%' . $digits . '%');
            } else {
                $qb->andWhere('LOWER(r.name) LIKE :q OR LOWER(r.city) LIKE :q OR LOWER(r.form) LIKE :q')->setParameter('q', '%' . \mb_strtolower($q) . '%');
            }
        }

        return $qb;
    }

    private static function filterDate(string $value, string $key): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = 1 === \preg_match('~^[0-9]{4}-[0-9]{2}-[0-9]{2}$~D', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;
        if (false === $date || $date->format('Y-m-d') !== $value || \str_starts_with($value, '0000-')) {
            $message = I18n::t('http.request_date_invalid');
            throw ContentException::invalid($message, [$key => $message]);
        }

        return $date;
    }

    /** @return array{total: int, new: int, failed: int} */
    public function counts(): array
    {
        $counts = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS total', 'SUM(CASE WHEN r.work = :work THEN 1 ELSE 0 END) AS newCount', 'SUM(CASE WHEN r.delivery = :delivery THEN 1 ELSE 0 END) AS failed')
            ->setParameter('work', Request::WORK_NEW)->setParameter('delivery', Request::DELIVERY_FAILED)->getQuery()->getSingleResult();

        return ['total' => (int) $counts['total'], 'new' => (int) $counts['newCount'], 'failed' => (int) $counts['failed']];
    }
}

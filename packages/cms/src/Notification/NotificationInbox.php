<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Viewer;

/**
 * The bell of the admin from the side of the reader: what a person sees (by the audience of each notification), what is unread, marking as read.
 * A person does not see what was sent before he joined. Writing is DatabaseChannel's job.
 */
final class NotificationInbox
{
    public const MAX_PAGE = 100;
    /** «Отметить всё» marks at most this many of the newest unread ones. */
    private const MARK_ALL_LIMIT = 1000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Newest first. `read` of an item = has this person read it.
     *
     * @return array{items: list<array<string, mixed>>, total: int, unread: int}
     */
    public function list(User $user, Viewer $viewer, bool $unreadOnly = false, int $limit = 30, int $offset = 0): array
    {
        $limit = \max(1, \min(self::MAX_PAGE, $limit));
        $rows = $this->query($user, $viewer, $unreadOnly)
            ->select('n', 'r.readAt AS readAt')
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setFirstResult(\max(0, $offset))->setMaxResults($limit)
            ->getQuery()->getResult();
        $items = [];
        foreach ($rows as $row) {
            $items[] = self::present($row[0], null !== $row['readAt']);
        }

        $total = $this->count($user, $viewer, $unreadOnly);

        return ['items' => $items, 'total' => $total, 'unread' => $unreadOnly ? $total : $this->unread($user, $viewer)];
    }

    public function unread(User $user, Viewer $viewer): int
    {
        return $this->count($user, $viewer, true);
    }

    /**
     * Marks notifications as read for this person (only those he can see). Returns the new number of unread ones.
     *
     * @param list<int> $ids
     */
    public function markRead(User $user, Viewer $viewer, array $ids): int
    {
        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ([] !== $ids) {
            $visible = $this->query($user, $viewer, true)->select('n.id')->andWhere('n.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getSingleColumnResult();
            $this->insertReads($user, \array_map('intval', $visible));
        }

        return $this->unread($user, $viewer);
    }

    /** Marks everything this person can see as read. Returns the new number of unread ones (0, unless more than MARK_ALL_LIMIT were waiting). */
    public function markAllRead(User $user, Viewer $viewer): int
    {
        $ids = $this->query($user, $viewer, true)->select('n.id')->orderBy('n.id', 'DESC')->setMaxResults(self::MARK_ALL_LIMIT)->getQuery()->getSingleColumnResult();
        $this->insertReads($user, \array_map('intval', $ids));

        return $this->unread($user, $viewer);
    }

    /** Takes back the notification of a matter that has been settled (a request delivered after a failure). Returns how many were removed. */
    public function resolve(string $key): int
    {
        return (int) $this->em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.dedupeKey = :k')->setParameter('k', $key)->execute();
    }

    /** Deletes notifications older than the given number of days. Returns how many. */
    public function prune(int $days, bool $dryRun = false): int
    {
        $before = new \DateTimeImmutable('-' . \max(1, $days) . ' days');
        if ($dryRun) {
            return (int) $this->em->createQuery('SELECT COUNT(n.id) FROM Itk\Cms\Entity\StoredNotification n WHERE n.createdAt < :b')->setParameter('b', $before)->getSingleScalarResult();
        }

        return (int) $this->em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.createdAt < :b')->setParameter('b', $before)->execute();
    }

    /** @return array<string, mixed> */
    public static function present(StoredNotification $n, bool $read): array
    {
        return [
            'id' => $n->id, 'type' => $n->type, 'title' => self::text($n->i18n['title'] ?? null, $n->title), 'body' => self::text($n->i18n['body'] ?? null, $n->body), 'link' => $n->link, 'level' => $n->level,
            'payload' => (object) $n->payload, 'read' => $read, 'createdAt' => $n->createdAt->format(\DATE_ATOM),
        ];
    }

    /**
     * The text in the language of the reader, when the notification keeps its key and the catalogues still have it; else what was stored.
     *
     * @param array{key: string, params: array<string, mixed>}|null $text
     */
    private static function text(?array $text, string $stored): string
    {
        return null !== $text && I18n::has($text['key']) ? I18n::t($text['key'], $text['params']) : $stored;
    }

    private function count(User $user, Viewer $viewer, bool $unreadOnly): int
    {
        return (int) $this->query($user, $viewer, $unreadOnly)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
    }

    /** What the person may see: his audiences, sent after he joined; optionally only the unread. */
    private function query(User $user, Viewer $viewer, bool $unreadOnly): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->from(StoredNotification::class, 'n')
            ->leftJoin('Itk\Cms\Entity\NotificationRead', 'r', 'WITH', 'IDENTITY(r.notification) = n.id AND IDENTITY(r.user) = :uid')
            ->setParameter('uid', $user->id)
            ->andWhere('n.createdAt >= :since')->setParameter('since', $user->createdAt);
        $audiences = $this->audiencesOf($viewer);
        if ([] === $audiences) {
            $qb->andWhere('n.audience IS NULL');
        } else {
            $qb->andWhere('(n.audience IS NULL OR n.audience IN (:aud))')->setParameter('aud', $audiences);
        }
        if ($unreadOnly) {
            $qb->andWhere('r.readAt IS NULL');
        }

        return $qb;
    }

    /**
     * The audiences in the table that include this person. Rights are worked out in PHP (roles are data), so the few distinct values are asked
     * of the table first and the notifications are then selected by SQL.
     *
     * @return list<string>
     */
    private function audiencesOf(Viewer $viewer): array
    {
        $all = $this->em->createQuery('SELECT DISTINCT n.audience FROM Itk\Cms\Entity\StoredNotification n WHERE n.audience IS NOT NULL')->getSingleColumnResult();

        return \array_values(\array_filter(\array_map('strval', $all), static fn (string $a): bool => Audience::matches($a, $viewer)));
    }

    /** @param list<int> $ids */
    private function insertReads(User $user, array $ids): void
    {
        if ([] === $ids) {
            return;
        }
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (\array_chunk($ids, 200) as $chunk) {
            $values = \implode(',', \array_fill(0, \count($chunk), '(?, ?, ?)'));
            $params = [];
            foreach ($chunk as $id) {
                \array_push($params, $id, $user->id, $now);
            }
            $conn->executeStatement('INSERT IGNORE INTO itk_notification_read (notification_id, user_id, read_at) VALUES ' . $values, $params);
        }
    }
}

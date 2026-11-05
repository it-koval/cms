<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\I18n\I18n;

/**
 * Keeps two writers away from the same page or item at the same moment: a read-change-write of a whole list (sections, fields) would
 * otherwise lose the edit of the one who saved first. A PostgreSQL advisory lock of the session, held by the connection until the work is done;
 * a second writer tries for a few seconds, then gets a plain message. The same key asked again by the same connection passes (it nests).
 *
 * @internal
 */
final class EditLock
{
    private const WAIT_SECONDS = 10;
    private const RETRY_MICROSECONDS = 100_000;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function around(string $kind, string $id, \Closure $work): mixed
    {
        $connection = $this->em->getConnection();
        $key = self::key($kind, $id);
        $deadline = \microtime(true) + self::WAIT_SECONDS;
        while (!$connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$key])) {
            if (\microtime(true) >= $deadline) {
                throw ContentException::conflict(I18n::t('content.edit_locked'));
            }
            \usleep(self::RETRY_MICROSECONDS);
        }
        try {
            return $work();
        } finally {
            $connection->fetchOne('SELECT pg_advisory_unlock(?)', [$key]);
        }
    }

    /** The 64-bit key of the advisory lock of a page or item. */
    public static function key(string $kind, string $id): int
    {
        return \unpack('q', \hash('md5', $kind . ':' . $id, true))[1];
    }
}

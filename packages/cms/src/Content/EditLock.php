<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\I18n\I18n;

/**
 * Keeps two writers away from the same page or item at the same moment: a read-change-write of a whole list (sections, fields) would
 * otherwise lose the edit of the one who saved first. A MySQL named lock, held by the connection until the work is done; a second
 * writer waits a few seconds, then gets a plain message. The same name asked again by the same connection passes (it nests).
 *
 * @internal
 */
final class EditLock
{
    private const WAIT_SECONDS = 10;

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
        $name = 'itk_' . \substr(\md5($kind . ':' . $id), 0, 24); // a lock name is at most 64 characters
        if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, self::WAIT_SECONDS])) {
            throw ContentException::conflict(I18n::t('content.edit_locked'));
        }
        try {
            return $work();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}

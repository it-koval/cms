<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Таблица-источник для проверки переноса строк (создаётся на время теста) и уборка за ним. Нужно ядро в self::getContainer(). */
trait SourceTable
{
    private const TABLE = 'test_gen_src';

    private function db(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function table(bool $primary = true): void
    {
        $db = $this->db();
        $db->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $db->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (id INT NOT NULL' . ($primary ? ' AUTO_INCREMENT' : '') . ', city VARCHAR(40) NOT NULL, note TEXT NULL COMMENT \'Комментарий из таблицы\', seats SMALLINT NOT NULL DEFAULT 0,'
            . " price DECIMAL(8,2) NULL, hot TINYINT(1) NOT NULL DEFAULT 0, start_on DATE NULL, starts_at DATETIME NULL, level ENUM('easy','hard') NOT NULL DEFAULT 'easy', photo BLOB NULL"
            . ($primary ? ', PRIMARY KEY (id)' : ', KEY (id)') . ')',
        );
        $db->executeStatement('INSERT INTO ' . self::TABLE . " (id, city, price, hot, starts_at) VALUES (1, 'Алтай', 12.50, 1, '2026-12-01 10:30:00'), (2, 'Байкал', NULL, 0, NULL), (3, 'Карелия', 7, 1, '2026-11-20 08:00:00')");
    }

    private function dropSourceTable(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\CollectionItem i WHERE i.collection LIKE :c')->setParameter('c', 'test_gen_%')->execute();
        $this->db()->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
    }
}

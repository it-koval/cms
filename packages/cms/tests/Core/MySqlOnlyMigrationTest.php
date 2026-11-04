<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use Itk\Cms\Migrations\Version20261029000000;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** ITK CMS is written for MySQL 8+ (JSON functions, utf8mb4_unicode_ci, InnoDB): the first migration stops with a plain explanation on any other database instead of failing in the middle. */
final class MySqlOnlyMigrationTest extends TestCase
{
    private function migration(Connection $connection): Version20261029000000
    {
        return new Version20261029000000($connection, new NullLogger());
    }

    #[Test]
    public function sqliteIsRefusedWithAnExplanation(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('only MySQL 8');
        $this->migration($sqlite)->up(new Schema());
    }

    #[Test]
    public function mariaDbIsRefusedToo(): void
    {
        $maria = $this->createStub(Connection::class);
        $maria->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('only MySQL 8');
        $this->migration($maria)->up(new Schema());
    }

    #[Test]
    public function mariaDbBehindAMySqlServerVersionIsRefusedByItsOwnVersion(): void
    {
        $maria = $this->createStub(Connection::class);
        $maria->method('getDatabasePlatform')->willReturn(new MySQLPlatform()); // DATABASE_URL says serverVersion=8.0.32
        $maria->method('fetchOne')->willReturn('10.11.2-MariaDB');

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('10.11.2-MariaDB');
        $this->migration($maria)->up(new Schema());
    }

    #[Test]
    public function mySql57IsRefused(): void
    {
        $old = $this->createStub(Connection::class);
        $old->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $old->method('fetchOne')->willReturn('5.7.44-log');

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('5.7.44-log');
        $this->migration($old)->up(new Schema());
    }

    #[Test]
    public function mySql8IsAccepted(): void
    {
        $ok = $this->createStub(Connection::class);
        $ok->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $ok->method('fetchOne')->willReturn('8.4.5');
        $migration = $this->migration($ok);

        $migration->up(new Schema());

        self::assertNotSame([], $this->sqlOf($migration), 'the statements of the schema are queued');
    }

    /** @return list<mixed> the statements AbstractMigration keeps for up() */
    private function sqlOf(Version20261029000000 $migration): array
    {
        return \Closure::bind(fn (): array => $this->plannedSql, $migration, AbstractMigration::class)();
    }
}

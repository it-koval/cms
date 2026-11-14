<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use Itk\Cms\Migrations\Version20261105000000;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** ITK CMS is written for PostgreSQL 16+ (jsonb, advisory locks, ON CONFLICT): the first migration stops with a plain explanation on any other database instead of failing in the middle. */
final class PostgresOnlyMigrationTest extends TestCase
{
    private function migration(Connection $connection): Version20261105000000
    {
        return new Version20261105000000($connection, new NullLogger());
    }

    #[Test]
    public function sqliteIsRefusedWithAnExplanation(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('only PostgreSQL 16');
        $this->migration($sqlite)->up(new Schema());
    }

    #[Test]
    public function mySqlIsRefusedToo(): void
    {
        $mysql = $this->createStub(Connection::class);
        $mysql->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('only PostgreSQL 16');
        $this->migration($mysql)->up(new Schema());
    }

    #[Test]
    public function postgres15IsRefusedByItsVersion(): void
    {
        $old = $this->createStub(Connection::class);
        $old->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $old->method('fetchOne')->willReturnMap([['SHOW server_version_num', [], [], '150008'], ['SHOW server_version', [], [], '15.8']]);

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('PostgreSQL 15.8');
        $this->migration($old)->up(new Schema());
    }

    #[Test]
    public function postgres16IsAccepted(): void
    {
        $ok = $this->createStub(Connection::class);
        $ok->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $ok->method('fetchOne')->willReturn('160000');
        $migration = $this->migration($ok);

        $migration->up(new Schema());

        self::assertNotSame([], $this->sqlOf($migration), 'the statements of the schema are queued');
    }

    /** @return list<mixed> the statements AbstractMigration keeps for up() */
    private function sqlOf(Version20261105000000 $migration): array
    {
        return \Closure::bind(fn (): array => $this->plannedSql, $migration, AbstractMigration::class)();
    }
}

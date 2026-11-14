<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Itk\Cms\Migrations\Version20261106100000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The knowledge-base migration stops with a clear message when the server has no pgvector 0.8 or newer, instead of failing in CREATE EXTENSION or on a vector column. */
final class PgvectorMigrationTest extends TestCase
{
    private function migration(string|false $version): Version20261106100000
    {
        $db = $this->createStub(Connection::class);
        $db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $db->method('fetchOne')->willReturn($version);

        return new Version20261106100000($db, new NullLogger());
    }

    /** @return iterable<string, array{string|false}> */
    public static function unusable(): iterable
    {
        yield 'extension not available on the server' => [false];
        yield 'pgvector 0.7' => ['0.7.4'];
    }

    #[Test]
    #[DataProvider('unusable')]
    public function aServerWithoutPgvector08IsRefusedWithAnExplanation(string|false $version): void
    {
        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('ITK CMS needs PostgreSQL with the pgvector extension 0.8 or newer: https://github.com/pgvector/pgvector#installation');
        $this->migration($version)->up(new Schema());
    }

    #[Test]
    public function pgvector08IsAccepted(): void
    {
        $migration = $this->migration('0.8.0');

        $migration->up(new Schema());

        self::addToAssertionCount(1);
    }
}

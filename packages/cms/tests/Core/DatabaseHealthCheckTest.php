<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Result;
use Itk\Cms\Health\DatabaseHealthCheck;
use Itk\Cms\Health\HealthResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The database check of «Состояние сайта» and itk:doctor: the server must be PostgreSQL 16 or newer, and the message names what it found. */
final class DatabaseHealthCheckTest extends KernelTestCase
{
    #[Test]
    public function theTestStandIsPostgres16OrNewer(): void
    {
        self::bootKernel();
        $result = (new DatabaseHealthCheck(self::getContainer()->get(Connection::class)))->check();

        self::assertSame(HealthResult::OK, $result->status);
        self::assertMatchesRegularExpression('/PostgreSQL (1[6-9]|[2-9]\d)\./', $result->message);
    }

    #[Test]
    public function mySqlIsAFailThatNamesTheServer(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $db->method('executeQuery')->willReturn($this->answer('8.4.5'));

        $result = (new DatabaseHealthCheck($db))->check();

        self::assertSame(HealthResult::FAIL, $result->status);
        self::assertStringContainsString('только на PostgreSQL 16', $result->message);
        self::assertStringContainsString('MySQL 8.4.5', $result->message);
    }

    #[Test]
    public function postgres15IsAFailThatNamesTheVersion(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $db->method('executeQuery')->willReturnMap([
            ['SELECT version()', [], [], null, $this->answer('PostgreSQL 15.8 on x86_64-pc-linux-gnu')],
            ['SHOW server_version_num', [], [], null, $this->answer('150008')],
        ]);

        $result = (new DatabaseHealthCheck($db))->check();

        self::assertSame(HealthResult::FAIL, $result->status);
        self::assertStringContainsString('PostgreSQL 15.8', $result->message);
        self::assertStringContainsString('Обновите PostgreSQL', $result->message);
    }

    #[Test]
    public function aDatabaseThatDoesNotAnswerIsAFail(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getDatabasePlatform')->willThrowException(new \RuntimeException('connection refused'));

        self::assertSame(HealthResult::FAIL, (new DatabaseHealthCheck($db))->check()->status);
    }

    private function answer(string $value): Result
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn($value);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** DBAL middleware of the test site: counts the queries that reach the database, so a test can say «not one per page». */
final class QueryCounter implements Middleware
{
    /** @var list<string> */
    public static array $queries = [];

    /** Runs $work and returns the SQL of every query it made. @return list<string> */
    public static function during(\Closure $work): array
    {
        $before = \count(self::$queries);
        $work();

        return \array_slice(self::$queries, $before);
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        QueryCounter::$queries[] = $sql;

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        QueryCounter::$queries[] = $sql;

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        QueryCounter::$queries[] = $sql;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}

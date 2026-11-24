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

final class DatabaseFaultMiddleware implements Middleware
{
    public function __construct(private readonly DatabaseFaults $faults)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->faults) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly DatabaseFaults $faults)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                if (null !== $this->faults->refusedPort) {
                    $params['host'] = '127.0.0.1';
                    $params['port'] = $this->faults->refusedPort;
                }

                return new class(parent::connect($params), $this->faults) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly DatabaseFaults $faults)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->faults->query();

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->faults->query();

                        return parent::query($sql);
                    }
                };
            }
        };
    }
}

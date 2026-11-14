<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Health\HealthCheckInterface;
use Itk\Cms\Health\HealthResult;

/** A check of an extension that is not connected. */
final class OffHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'Test выключенный модуль';
    }

    public function check(): HealthResult
    {
        return HealthResult::off('Не подключён');
    }
}

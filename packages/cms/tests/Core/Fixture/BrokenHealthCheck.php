<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Health\HealthCheckInterface;
use Itk\Cms\Health\HealthResult;

/** A check that throws: the screen shows it as failed instead of breaking. */
final class BrokenHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'Test сломанная проверка';
    }

    public function check(): HealthResult
    {
        throw new \RuntimeException('нет связи с bot123456:AAH-secret_token');
    }
}

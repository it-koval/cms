<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Health\HealthCheckInterface;
use Itk\Cms\Health\HealthResult;

/** A check of an extension that is not happy. */
final class WarnHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'Test платёжный шлюз';
    }

    public function check(): HealthResult
    {
        return HealthResult::warn('Ответ шлюза медленнее обычного');
    }
}

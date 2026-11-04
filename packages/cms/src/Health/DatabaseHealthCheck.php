<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
use Itk\Cms\I18n\I18n;

/** @internal */
final class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.health_database_name');
    }

    public function check(): HealthResult
    {
        try {
            $this->db->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            return HealthResult::fail(I18n::t('ops.health_database_down'));
        }

        return HealthResult::ok(I18n::t('ops.health_database_ok'));
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Migrations\Version20261105000000;

/** The database answers, and it is the PostgreSQL the bundle is written for (16 and newer). @internal */
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
            $platform = $this->db->getDatabasePlatform();
            $postgres = $platform instanceof PostgreSQLPlatform;
            $version = (string) $this->db->executeQuery('SELECT version()')->fetchOne();
            $number = $postgres ? (int) $this->db->executeQuery('SHOW server_version_num')->fetchOne() : 0;
        } catch (\Throwable) {
            return HealthResult::fail(I18n::t('ops.health_database_down'));
        }
        if (!$postgres) {
            return HealthResult::fail(I18n::t('ops.health_database_not_postgres', ['server' => \preg_replace('/Platform$/', '', (new \ReflectionClass($platform))->getShortName()) . ' ' . $version]));
        }
        if ($number < Version20261105000000::MIN_SERVER_VERSION_NUM) {
            return HealthResult::fail(I18n::t('ops.health_database_old', ['server' => $version]));
        }

        return HealthResult::ok(I18n::t('ops.health_database_ok', ['server' => \preg_replace('/^(PostgreSQL \S+).*/s', '$1', $version)]));
    }
}

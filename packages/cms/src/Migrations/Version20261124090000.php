<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Dedicated internal keys cannot collide with schema keys (which do not allow a colon). */
final class Version20261124090000 extends AbstractMigration
{
    private const KEYS = ['mail', 'mailStatus', 'adminTheme', 'mcpEnabled', 'telegramBot', 'maxBot', 'yandexWebmaster', 'tgOn', 'maxOn', 'tgRoutes', 'maxRoutes', 'mailRoutes'];

    public function getDescription(): string
    {
        return 'ITK CMS: isolate internal settings in the itk: namespace';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'ITK CMS supports only PostgreSQL 16 and newer.');
        foreach (self::KEYS as $key) {
            // If the destination exists, the legacy key may already be a site field. Preserve both.
            $shape = \in_array($key, ['mcpEnabled', 'tgOn', 'maxOn'], true) ? 'boolean' : 'object';
            // Numeric form keys such as "0" are encoded by PHP as JSON arrays.
            $shapes = \in_array($key, ['tgRoutes', 'maxRoutes', 'mailRoutes'], true) ? "'object', 'array'" : "'" . $shape . "'";
            $this->addSql('UPDATE itk_setting SET setting_key = ? WHERE setting_key = ? AND locale = ? AND jsonb_typeof(setting_value::jsonb) IN (' . $shapes . ') AND NOT EXISTS (SELECT 1 FROM itk_setting WHERE setting_key = ? AND locale = ?)', ['itk:' . $key, $key, '*', 'itk:' . $key, '*']);
        }
    }

    public function down(Schema $schema): void
    {
        // A site may now use a legacy key. Keep that site value intact when rolling back.
        foreach (self::KEYS as $key) {
            $this->addSql('UPDATE itk_setting SET setting_key = ? WHERE setting_key = ? AND locale = ? AND NOT EXISTS (SELECT 1 FROM itk_setting WHERE setting_key = ? AND locale = ?)', [$key, 'itk:' . $key, '*', $key, '*']);
        }
    }
}

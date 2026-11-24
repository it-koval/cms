<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isolate SMTP settings while preserving a site's unrelated shared field named mail.
 *
 * @internal
 */
final class Version20261124110100 extends AbstractMigration
{
    /** The complete typed object written by MailSettings::save(), including disabled configurations. */
    private const SMTP_OBJECT = <<<'SQL'
COALESCE(
    jsonb_typeof(s.setting_value) = 'object'
    AND jsonb_typeof(s.setting_value->'enabled') = 'boolean'
    AND jsonb_typeof(s.setting_value->'host') = 'string'
    AND jsonb_typeof(s.setting_value->'port') = 'number'
    AND jsonb_typeof(s.setting_value->'encryption') = 'string'
    AND s.setting_value->>'encryption' IN ('none', 'ssl', 'starttls')
    AND jsonb_typeof(s.setting_value->'username') = 'string'
    AND jsonb_typeof(s.setting_value->'password') = 'string'
    AND jsonb_typeof(s.setting_value->'fromAddress') = 'string'
    AND jsonb_typeof(s.setting_value->'fromName') = 'string',
    FALSE
)
SQL;

    public function getDescription(): string
    {
        return 'ITK CMS: isolate SMTP settings without moving unrelated site mail values';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'ITK CMS supports only PostgreSQL 16 and newer.');
        if (!$schema->hasTable('itk_setting')) {
            return;
        }

        // Also repair a site value renamed by an earlier broad namespace migration; never overwrite either existing destination.
        $this->addSql('UPDATE itk_setting s SET setting_key = ? WHERE s.setting_key = ? AND s.locale = ? AND NOT ' . self::SMTP_OBJECT
            . ' AND NOT EXISTS (SELECT 1 FROM itk_setting d WHERE d.setting_key = ? AND d.locale = ?)', ['mail', 'itk:mail', '*', 'mail', '*']);
        $this->addSql('UPDATE itk_setting s SET setting_key = ? WHERE s.setting_key = ? AND s.locale = ? AND ' . self::SMTP_OBJECT
            . ' AND NOT EXISTS (SELECT 1 FROM itk_setting d WHERE d.setting_key = ? AND d.locale = ?)', ['itk:mail', 'mail', '*', 'itk:mail', '*']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('SMTP settings must not overwrite a site field when rolling back.');
    }
}

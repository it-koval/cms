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
        return 'ITK CMS: isolate SMTP settings without modifying legacy site mail values';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'ITK CMS supports only PostgreSQL 16 and newer.');
        if (!$schema->hasTable('itk_setting')) {
            return;
        }

        // Copy back a site value renamed by an earlier namespace migration; retain both sources.
        $this->addSql('INSERT INTO itk_setting (setting_key, locale, setting_value) SELECT ?, s.locale, s.setting_value FROM itk_setting s WHERE s.setting_key = ? AND s.locale = ? AND NOT ' . self::SMTP_OBJECT
            . ' ON CONFLICT (setting_key, locale) DO NOTHING', ['mail', 'itk:mail', '*']);
        $this->addSql('INSERT INTO itk_setting (setting_key, locale, setting_value) SELECT ?, s.locale, s.setting_value FROM itk_setting s WHERE s.setting_key = ? AND s.locale = ? AND ' . self::SMTP_OBJECT
            . ' ON CONFLICT (setting_key, locale) DO NOTHING', ['itk:mail', 'mail', '*']);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('SMTP settings must not overwrite a site field when rolling back.');
    }
}

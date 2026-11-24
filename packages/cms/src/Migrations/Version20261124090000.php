<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dedicated internal keys cannot collide with schema keys (which do not allow a colon).
 *
 * @internal
 */
final class Version20261124090000 extends AbstractMigration
{
    private const VALUE_KEYS = ['mcpEnabled', 'mail', 'mailStatus', 'adminTheme', 'telegramBot', 'maxBot', 'yandexWebmaster'];
    private const FLAG_KEYS = ['tgOn', 'maxOn', 'forms'];
    private const ROUTE_KEYS = ['tgRoutes', 'maxRoutes', 'mailRoutes'];

    public function getDescription(): string
    {
        return 'ITK CMS: isolate internal settings in the itk: namespace';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'ITK CMS supports only PostgreSQL 16 and newer.');
        // Legacy names can belong to site fields, even when they look exactly like credentials.
        // Never rename or delete them. Existing internal destinations always win.
        foreach (self::VALUE_KEYS as $key) {
            $this->addSql('INSERT INTO itk_setting (setting_key, locale, setting_value) SELECT ?, locale, setting_value FROM itk_setting WHERE setting_key = ? AND locale = ? ON CONFLICT (setting_key, locale) DO NOTHING', ['itk:' . $key, $key, '*']);
        }
        foreach (self::FLAG_KEYS as $key) {
            // v0.2.2 read (bool) ($decoded ?? true); Doctrine decoded JSON objects as associative arrays.
            $this->addSql(<<<'SQL'
                INSERT INTO itk_setting (setting_key, locale, setting_value)
                SELECT ?, locale, to_jsonb(CASE jsonb_typeof(setting_value::jsonb)
                    WHEN 'null' THEN TRUE
                    WHEN 'boolean' THEN setting_value::jsonb = 'true'::jsonb
                    WHEN 'number' THEN setting_value::jsonb <> '0'::jsonb
                    WHEN 'string' THEN (setting_value::jsonb #>> '{}') NOT IN ('', '0')
                    WHEN 'array' THEN setting_value::jsonb <> '[]'::jsonb
                    WHEN 'object' THEN setting_value::jsonb <> '{}'::jsonb
                    END)
                FROM itk_setting WHERE setting_key = ? AND locale = ?
                ON CONFLICT (setting_key, locale) DO NOTHING
                SQL, ['itk:' . $key, $key, '*']);
        }
        foreach (self::ROUTE_KEYS as $key) {
            // Match (array) ($decoded ?? []), including scalar routes for the form key "0".
            $this->addSql(<<<'SQL'
                INSERT INTO itk_setting (setting_key, locale, setting_value)
                SELECT ?, locale, CASE jsonb_typeof(setting_value::jsonb)
                    WHEN 'null' THEN '[]'::jsonb
                    WHEN 'array' THEN setting_value::jsonb
                    WHEN 'object' THEN setting_value::jsonb
                    ELSE jsonb_build_array(setting_value::jsonb)
                    END
                FROM itk_setting WHERE setting_key = ? AND locale = ?
                ON CONFLICT (setting_key, locale) DO NOTHING
                SQL, ['itk:' . $key, $key, '*']);
        }
    }

    public function down(Schema $schema): void
    {
        // Rollback also copies only: either namespace may have acquired new site-owned values.
        foreach ([...self::VALUE_KEYS, ...self::FLAG_KEYS, ...self::ROUTE_KEYS] as $key) {
            $this->addSql('INSERT INTO itk_setting (setting_key, locale, setting_value) SELECT ?, locale, setting_value FROM itk_setting WHERE setting_key = ? AND locale = ? ON CONFLICT (setting_key, locale) DO NOTHING', [$key, 'itk:' . $key, '*']);
        }
    }
}

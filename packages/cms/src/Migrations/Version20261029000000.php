<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS: initial schema (MySQL 8). */
final class Version20261029000000 extends AbstractMigration
{
    private const TABLE_OPTIONS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

    public function getDescription(): string
    {
        return 'ITK CMS: pages, collections, menus, settings, media, redirects, requests, users, roles';
    }

    public function up(Schema $schema): void
    {
        // JSON columns and filters, utf8mb4_unicode_ci and InnoDB: the whole bundle is written for MySQL 8; stop here with a reason, not in the middle with a syntax error
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform || $platform instanceof MariaDBPlatform,
            \sprintf('ITK CMS supports only MySQL 8 and newer (MariaDB, PostgreSQL, SQLite and other databases are not supported). DATABASE_URL points to a database of the type "%s".', $platform::class),
        );
        $version = (string) $this->connection->fetchOne('SELECT VERSION()');
        // the platform comes from serverVersion in DATABASE_URL, so MariaDB can pass above as MySQL; the server itself tells the truth
        $this->abortIf(false !== \stripos($version, 'mariadb'), \sprintf('ITK CMS supports only MySQL 8 and newer (MariaDB is not supported), but the server is %s. Point DATABASE_URL to a MySQL server.', $version));
        $this->abortIf(\version_compare($version, '8.0', '<'), \sprintf('ITK CMS supports only MySQL 8 and newer, but the server is MySQL %s. Update MySQL or point DATABASE_URL to another server.', $version));

        $o = self::TABLE_OPTIONS;

        $this->addSql("CREATE TABLE itk_page (id VARCHAR(36) NOT NULL, parent_id VARCHAR(36) DEFAULT NULL, position INT NOT NULL, template VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, INDEX itk_page_parent_idx (parent_id), PRIMARY KEY(id)) $o");
        $this->addSql("CREATE TABLE itk_page_translation (id VARCHAR(36) NOT NULL, page_id VARCHAR(36) NOT NULL, locale VARCHAR(8) NOT NULL, draft JSON NOT NULL, published JSON DEFAULT NULL, status VARCHAR(16) NOT NULL, published_url VARCHAR(255) DEFAULT NULL, awaiting JSON DEFAULT NULL, returned_note JSON DEFAULT NULL, updated_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, migrated TINYINT(1) NOT NULL, UNIQUE INDEX itk_page_translation_page_locale (page_id, locale), UNIQUE INDEX itk_page_translation_url (locale, published_url), PRIMARY KEY(id)) $o");
        $this->addSql("CREATE TABLE itk_collection_item (id VARCHAR(36) NOT NULL, collection_key VARCHAR(64) NOT NULL, position INT NOT NULL, translations JSON NOT NULL, created_at DATETIME NOT NULL, INDEX itk_collection_item_collection_idx (collection_key, position), PRIMARY KEY(id)) $o");
        $this->addSql("CREATE TABLE itk_menu_area (area_key VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, depth SMALLINT NOT NULL, items JSON NOT NULL, PRIMARY KEY(area_key)) $o");
        $this->addSql("CREATE TABLE itk_setting (setting_key VARCHAR(100) NOT NULL, locale VARCHAR(8) NOT NULL, setting_value JSON DEFAULT NULL, PRIMARY KEY(setting_key, locale)) $o");
        $this->addSql("CREATE TABLE itk_media_file (id VARCHAR(36) NOT NULL, path VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime VARCHAR(120) NOT NULL, file_size INT NOT NULL, width INT NOT NULL, height INT NOT NULL, alt VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id)) $o");
        $this->addSql("CREATE TABLE itk_redirect (id VARCHAR(36) NOT NULL, from_path VARCHAR(255) NOT NULL, to_path VARCHAR(512) NOT NULL, code SMALLINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX itk_redirect_from (from_path), PRIMARY KEY(id)) $o");
        $this->addSql("CREATE TABLE itk_request (id INT AUTO_INCREMENT NOT NULL, form_key VARCHAR(64) NOT NULL, data JSON NOT NULL, phone VARCHAR(32) DEFAULT NULL, name VARCHAR(120) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, work VARCHAR(16) NOT NULL, delivery VARCHAR(16) NOT NULL, delivery_error LONGTEXT DEFAULT NULL, source_url VARCHAR(512) DEFAULT NULL, locale VARCHAR(8) NOT NULL, ip_hash VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX itk_request_created_idx (created_at), PRIMARY KEY(id)) $o");
        $this->addSql("CREATE TABLE itk_role (role_key VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(500) NOT NULL, builtin TINYINT(1) NOT NULL, perms JSON NOT NULL, templates JSON DEFAULT NULL, PRIMARY KEY(role_key)) $o");
        $this->addSql("CREATE TABLE itk_user (id VARCHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(120) NOT NULL, password_hash VARCHAR(255) NOT NULL, role_key VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, last_sign_in_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX itk_user_email (email), PRIMARY KEY(id)) $o");

        $this->addSql('ALTER TABLE itk_page ADD CONSTRAINT FK_ITK_PAGE_PARENT FOREIGN KEY (parent_id) REFERENCES itk_page (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE itk_page_translation ADD CONSTRAINT FK_ITK_PAGE_TRANSLATION_PAGE FOREIGN KEY (page_id) REFERENCES itk_page (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_page_translation DROP FOREIGN KEY FK_ITK_PAGE_TRANSLATION_PAGE');
        $this->addSql('ALTER TABLE itk_page DROP FOREIGN KEY FK_ITK_PAGE_PARENT');
        foreach (['itk_user', 'itk_role', 'itk_request', 'itk_redirect', 'itk_media_file', 'itk_setting', 'itk_menu_area', 'itk_collection_item', 'itk_page_translation', 'itk_page'] as $table) {
            $this->addSql("DROP TABLE $table");
        }
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS: keys of AI assistants (MCP) and the mark «через ИИ-ассистента» in the history. */
final class Version20261101100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_api_token, itk_revision.via / via_name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_api_token (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, name VARCHAR(120) NOT NULL, token_hash VARCHAR(64) NOT NULL, hint VARCHAR(8) NOT NULL, scopes JSON NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, INDEX itk_api_token_user_idx (user_id), UNIQUE INDEX itk_api_token_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE itk_api_token ADD CONSTRAINT FK_ITK_API_TOKEN_USER FOREIGN KEY (user_id) REFERENCES itk_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE itk_revision ADD via VARCHAR(16) DEFAULT NULL, ADD via_name VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_revision DROP via, DROP via_name');
        $this->addSql('ALTER TABLE itk_api_token DROP FOREIGN KEY FK_ITK_API_TOKEN_USER');
        $this->addSql('DROP TABLE itk_api_token');
    }
}

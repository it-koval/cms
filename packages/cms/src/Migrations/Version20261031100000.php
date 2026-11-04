<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS: links for setting a password (forgot password, invitation by e-mail). */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_password_reset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_password_reset (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(16) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX itk_password_reset_user_idx (user_id), INDEX itk_password_reset_expires_idx (expires_at), UNIQUE INDEX itk_password_reset_hash (token_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE itk_password_reset ADD CONSTRAINT FK_ITK_PASSWORD_RESET_USER FOREIGN KEY (user_id) REFERENCES itk_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_password_reset DROP FOREIGN KEY FK_ITK_PASSWORD_RESET_USER');
        $this->addSql('DROP TABLE itk_password_reset');
    }
}

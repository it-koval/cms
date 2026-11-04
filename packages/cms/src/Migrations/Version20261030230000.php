<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2: the second sign-in factor (TOTP) of a person and «двухэтапный вход обязателен» for a role. */
final class Version20261030230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_user_2fa, itk_role.require_2fa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_user_2fa (user_id VARCHAR(36) NOT NULL, secret VARCHAR(255) NOT NULL, enabled_at DATETIME DEFAULT NULL, recovery_codes JSON NOT NULL, last_step INT DEFAULT NULL, failed_attempts SMALLINT NOT NULL, locked_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE itk_user_2fa ADD CONSTRAINT FK_ITK_USER_2FA_USER FOREIGN KEY (user_id) REFERENCES itk_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE itk_role ADD require_2fa TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_role DROP require_2fa');
        $this->addSql('ALTER TABLE itk_user_2fa DROP FOREIGN KEY FK_ITK_USER_2FA_USER');
        $this->addSql('DROP TABLE itk_user_2fa');
    }
}

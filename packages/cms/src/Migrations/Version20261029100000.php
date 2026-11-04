<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2: the settings of one person that follow him between devices (filters and sorting of lists). */
final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_user_pref';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_user_pref (user_id VARCHAR(36) NOT NULL, pref_key VARCHAR(100) NOT NULL, pref_value JSON DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(user_id, pref_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE itk_user_pref ADD CONSTRAINT FK_ITK_USER_PREF_USER FOREIGN KEY (user_id) REFERENCES itk_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_user_pref DROP FOREIGN KEY FK_ITK_USER_PREF_USER');
        $this->addSql('DROP TABLE itk_user_pref');
    }
}

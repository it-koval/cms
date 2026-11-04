<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2: the history of changes (who changed a page, a record, settings or a menu, and the whole version after it). */
final class Version20261030100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_revision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_revision (id INT AUTO_INCREMENT NOT NULL, resource_kind VARCHAR(16) NOT NULL, resource_id VARCHAR(64) NOT NULL, resource_key VARCHAR(64) NOT NULL, locale VARCHAR(8) NOT NULL, action VARCHAR(16) NOT NULL, user_id VARCHAR(36) DEFAULT NULL, user_name VARCHAR(180) DEFAULT NULL, title VARCHAR(255) NOT NULL, snapshot JSON NOT NULL, summary JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX itk_revision_object_idx (resource_kind, resource_id, locale), INDEX itk_revision_action_idx (action, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE itk_revision');
    }
}

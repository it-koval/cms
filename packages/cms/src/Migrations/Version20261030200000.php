<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2: notifications of the admin (the bell): the messages and who has read them. */
final class Version20261030200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_notification, itk_notification_read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_notification (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, link VARCHAR(512) DEFAULT NULL, level VARCHAR(16) NOT NULL, audience VARCHAR(100) DEFAULT NULL, dedupe_key VARCHAR(120) DEFAULT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, INDEX itk_notification_created_idx (created_at), INDEX itk_notification_key_idx (dedupe_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE itk_notification_read (notification_id INT NOT NULL, user_id VARCHAR(36) NOT NULL, read_at DATETIME NOT NULL, INDEX IDX_5384E5CBEF1A9D84 (notification_id), INDEX IDX_5384E5CBA76ED395 (user_id), PRIMARY KEY(notification_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE itk_notification_read ADD CONSTRAINT FK_5384E5CBEF1A9D84 FOREIGN KEY (notification_id) REFERENCES itk_notification (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE itk_notification_read ADD CONSTRAINT FK_5384E5CBA76ED395 FOREIGN KEY (user_id) REFERENCES itk_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_notification_read DROP FOREIGN KEY FK_5384E5CBA76ED395');
        $this->addSql('ALTER TABLE itk_notification_read DROP FOREIGN KEY FK_5384E5CBEF1A9D84');
        $this->addSql('DROP TABLE itk_notification_read');
        $this->addSql('DROP TABLE itk_notification');
    }
}

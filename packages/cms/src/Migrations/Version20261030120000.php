<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2, media library: folders, the folder of a file, the focal point and the frames of an image. */
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_media_folder, itk_media_file.folder_id / focal / crops';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_media_folder (id VARCHAR(36) NOT NULL, parent_id VARCHAR(36) DEFAULT NULL, name VARCHAR(120) NOT NULL, position INT NOT NULL, INDEX itk_media_folder_parent_idx (parent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE itk_media_folder ADD CONSTRAINT FK_ITK_MEDIA_FOLDER_PARENT FOREIGN KEY (parent_id) REFERENCES itk_media_folder (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE itk_media_file ADD folder_id VARCHAR(36) DEFAULT NULL, ADD focal JSON DEFAULT NULL, ADD crops JSON DEFAULT NULL');
        $this->addSql('CREATE INDEX itk_media_file_folder_idx ON itk_media_file (folder_id)');
        $this->addSql('ALTER TABLE itk_media_file ADD CONSTRAINT FK_ITK_MEDIA_FILE_FOLDER FOREIGN KEY (folder_id) REFERENCES itk_media_folder (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_media_file DROP FOREIGN KEY FK_ITK_MEDIA_FILE_FOLDER');
        $this->addSql('DROP INDEX itk_media_file_folder_idx ON itk_media_file');
        $this->addSql('ALTER TABLE itk_media_file DROP folder_id, DROP focal, DROP crops');
        $this->addSql('ALTER TABLE itk_media_folder DROP FOREIGN KEY FK_ITK_MEDIA_FOLDER_PARENT');
        $this->addSql('DROP TABLE itk_media_folder');
    }
}

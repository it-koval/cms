<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2: records of a collection can nest (collections with tree()): the parent record. */
final class Version20261030130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_collection_item.parent_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_collection_item ADD parent_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX itk_collection_item_parent_idx ON itk_collection_item (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX itk_collection_item_parent_idx ON itk_collection_item');
        $this->addSql('ALTER TABLE itk_collection_item DROP parent_id');
    }
}

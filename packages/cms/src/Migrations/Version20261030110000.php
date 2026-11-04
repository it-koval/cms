<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS iteration 2: meta tags (SEO) for any address of the site. */
final class Version20261030110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_seo_rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_seo_rule (id VARCHAR(36) NOT NULL, path VARCHAR(500) NOT NULL, match_type VARCHAR(8) NOT NULL, locale VARCHAR(8) DEFAULT NULL, title VARCHAR(255) NOT NULL, description VARCHAR(500) NOT NULL, robots VARCHAR(60) NOT NULL, canonical VARCHAR(512) NOT NULL, og_image VARCHAR(512) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX itk_seo_rule_active_idx (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE itk_seo_rule');
    }
}

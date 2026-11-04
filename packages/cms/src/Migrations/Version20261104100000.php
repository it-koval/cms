<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS: the critical CSS of live pages, one row per language version (itk_critical_css). */
final class Version20261104100000 extends AbstractMigration
{
    private const TABLE_OPTIONS = 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB';

    public function getDescription(): string
    {
        return 'ITK CMS: itk_critical_css';
    }

    public function up(Schema $schema): void
    {
        $o = self::TABLE_OPTIONS;

        $this->addSql("CREATE TABLE itk_critical_css (translation_id VARCHAR(36) NOT NULL, basis VARCHAR(40) NOT NULL, inputs VARCHAR(40) NOT NULL, status VARCHAR(16) NOT NULL, styles JSON DEFAULT NULL, error VARCHAR(500) DEFAULT NULL, generated_at DATETIME NOT NULL, PRIMARY KEY(translation_id)) $o");
        $this->addSql('ALTER TABLE itk_critical_css ADD CONSTRAINT FK_ITK_CRITICAL_CSS_TRANSLATION FOREIGN KEY (translation_id) REFERENCES itk_page_translation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_critical_css DROP FOREIGN KEY FK_ITK_CRITICAL_CSS_TRANSLATION');
        $this->addSql('DROP TABLE itk_critical_css');
    }
}

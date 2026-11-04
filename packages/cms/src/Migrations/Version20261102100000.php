<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** ITK CMS: the language of the interface a person chose, and the keys of the texts of a notification (the bell draws them in the language of the reader). */
final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_user.locale, itk_notification.i18n';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_user ADD locale VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE itk_notification ADD i18n JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_notification DROP i18n');
        $this->addSql('ALTER TABLE itk_user DROP locale');
    }
}

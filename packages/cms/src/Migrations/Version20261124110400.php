<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** @internal */
final class Version20261124110400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: durable per-recipient checkpoints for request email delivery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE itk_request_mail_delivery (delivery_key VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, PRIMARY KEY (delivery_key))');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Dropping SMTP checkpoints could send already accepted request emails again.');
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** @internal */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: durable outbox for request notification publication';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'ITK CMS supports only PostgreSQL 16 and newer.');
        $this->addSql('CREATE TABLE itk_request_queue (id VARCHAR(32) NOT NULL, request_id INT NOT NULL, kind VARCHAR(16) NOT NULL, payload JSONB NOT NULL, available_at INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX itk_request_queue_available_idx ON itk_request_queue (available_at)');
        $this->addSql('CREATE INDEX IDX_1020C442427EB8A5 ON itk_request_queue (request_id)');
        $this->addSql('ALTER TABLE itk_request_queue ADD CONSTRAINT FK_1020C442427EB8A5 FOREIGN KEY (request_id) REFERENCES itk_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE itk_request_queue');
    }
}

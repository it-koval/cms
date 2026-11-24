<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ITK CMS: the delivery of a request to each messenger (itk_request.channels). `delivery` and `delivery_error` stay as the sum of them.
 * What was delivered or refused before the column existed was Telegram's.
 *
 * @internal
 */
final class Version20261110100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_request.channels (the delivery of a request to each messenger)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof PostgreSQLPlatform, \sprintf('ITK CMS supports only PostgreSQL 16 and newer. DATABASE_URL points to a database of the type "%s".', $platform::class));

        $this->addSql('ALTER TABLE itk_request ADD channels JSONB DEFAULT \'{}\' NOT NULL');
        $this->addSql('UPDATE itk_request SET channels = jsonb_build_object(\'telegram\', jsonb_build_object(\'state\', delivery, \'error\', delivery_error)) WHERE delivery <> \'pending\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_request DROP channels');
    }
}

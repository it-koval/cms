<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ITK CMS: itk_critical_css.stamp, the page-independent part of the basis (the container build and the stylesheets stamp).
 * Rows made before the column existed have none and count as not current in the health check until the worker rewrites them.
 *
 * @internal
 */
final class Version20261110200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_critical_css.stamp (the page-independent part of the basis)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof PostgreSQLPlatform, \sprintf('ITK CMS supports only PostgreSQL 16 and newer. DATABASE_URL points to a database of the type "%s".', $platform::class));

        $this->addSql('ALTER TABLE itk_critical_css ADD stamp VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE itk_critical_css DROP stamp');
    }
}

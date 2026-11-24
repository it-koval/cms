<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Itk\Cms\I18n\I18n;

/** Keep dates created under Doctrine's default naming strategy when adopting the explicit column name. */
final class Version20261124090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return I18n::t('ops.migration_page_created_description');
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, I18n::t('ops.health_database_not_postgres', ['server' => $this->connection->getDatabasePlatform()::class]));
        $columns = $this->connection->createSchemaManager()->listTableColumns('itk_page');
        if (isset($columns['created_at'])) {
            return;
        }
        foreach ($columns as $column) {
            if ('createdat' === \strtolower($column->getName())) {
                $this->addSql('ALTER TABLE itk_page RENAME COLUMN ' . $this->connection->getDatabasePlatform()->quoteSingleIdentifier($column->getName()) . ' TO created_at');

                return;
            }
        }
        $this->abortIf(true, I18n::t('ops.migration_page_created_missing'));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(I18n::t('ops.migration_page_created_irreversible'));
    }
}

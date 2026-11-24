<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expire account links issued before token-free delivery jobs and full-origin trust.
 *
 * @internal
 */
final class Version20261124110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: revoke outstanding pre-upgrade password and invitation links';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('itk_password_reset')) {
            $this->addSql('UPDATE itk_password_reset SET used_at = CURRENT_TIMESTAMP WHERE used_at IS NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Revoked account links must never be restored.');
    }
}

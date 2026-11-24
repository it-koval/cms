<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** @internal */
final class Version20261124090200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: enforce one notification per deduplication key, preserving read status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('LOCK TABLE itk_notification, itk_notification_read IN SHARE ROW EXCLUSIVE MODE');
        $this->addSql(<<<'SQL'
            INSERT INTO itk_notification_read (notification_id, user_id, read_at)
            SELECT keep.id, r.user_id, MIN(r.read_at)
            FROM (
                SELECT dedupe_key, MIN(id) AS id
                FROM itk_notification
                WHERE dedupe_key IS NOT NULL
                GROUP BY dedupe_key
                HAVING COUNT(*) > 1
            ) keep
            JOIN itk_notification n ON n.dedupe_key = keep.dedupe_key
            JOIN itk_notification_read r ON r.notification_id = n.id
            GROUP BY keep.id, r.user_id
            ON CONFLICT (notification_id, user_id) DO UPDATE
            SET read_at = LEAST(itk_notification_read.read_at, EXCLUDED.read_at)
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM itk_notification n
            USING (
                SELECT dedupe_key, MIN(id) AS id
                FROM itk_notification
                WHERE dedupe_key IS NOT NULL
                GROUP BY dedupe_key
                HAVING COUNT(*) > 1
            ) keep
            WHERE n.dedupe_key = keep.dedupe_key AND n.id <> keep.id
            SQL);
        $this->addSql('DROP INDEX itk_notification_key_idx');
        $this->addSql('CREATE UNIQUE INDEX itk_notification_key_idx ON itk_notification (dedupe_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX itk_notification_key_idx');
        $this->addSql('CREATE INDEX itk_notification_key_idx ON itk_notification (dedupe_key)');
    }
}

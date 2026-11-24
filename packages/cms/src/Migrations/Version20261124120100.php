<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Itk\Cms\Content\SeoRuleService;

/** @internal */
final class Version20261124120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: canonicalize legacy percent-encoded SEO rule paths, preserving colliding rules';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'ITK CMS supports only PostgreSQL 16 and newer.');
        $groups = [];
        // v0.2.2 stored Url::key(path), retaining escaped letters. Query bytes already have their
        // own canonical form and must not be parsed, sorted or case-folded during this upgrade.
        foreach ($this->connection->fetchAllAssociative('SELECT id, path, match_type, locale FROM itk_seo_rule ORDER BY id') as $row) {
            [$path, $query] = \array_pad(\explode('?', $row['path'], 2), 2, null);
            $canonical = SeoRuleService::requestPathKey($path) . (null === $query ? '' : '?' . $query);
            $key = \json_encode([$canonical, $row['match_type'], $row['locale']], \JSON_THROW_ON_ERROR);
            $groups[$key][] = ['id' => $row['id'], 'before' => $row['path'], 'after' => $canonical];
        }
        foreach ($groups as $rows) {
            $changed = \array_filter($rows, static fn (array $row): bool => $row['before'] !== $row['after']);
            if ([] === $changed) {
                continue;
            }
            if (\count($rows) > 1) {
                // Include inactive rules too: reactivating one must not reveal a silently overwritten rule.
                $this->warnIf(true, 'SEO path normalization collision: rules ' . \implode(', ', \array_column($rows, 'id')) . ' preserved unchanged. Review these IDs in Settings > SEO > Address rules; merge or change their addresses manually, then save each retained rule to normalize its path.');
                continue;
            }
            $row = $rows[0];
            $this->addSql('UPDATE itk_seo_rule SET path = ? WHERE id = ?', [$row['after'], $row['id']], [ParameterType::STRING, ParameterType::STRING]);
        }
        // RedirectService did not change to selective percent decoding: its source-path lookup still
        // uses Url::key(). Decoding legacy redirects here would change which requests they answer.
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The original percent-encoded spelling of SEO rule paths cannot be reconstructed safely.');
    }
}

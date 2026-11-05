<?php

declare(strict_types=1);

namespace Itk\Cms\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ITK CMS: the knowledge base (itk_kb_document, itk_kb_chunk) on pgvector.
 * Runs whether the knowledge base is on or off: the tables stay empty until it is enabled. 1536 is KnowledgeBase\Embedding\Space::DIMENSIONS;
 * Doctrine\VectorType declares the column from the constant, so doctrine:schema:validate fails if they ever disagree.
 */
final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ITK CMS: itk_kb_document, itk_kb_chunk (pgvector)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof PostgreSQLPlatform, \sprintf('ITK CMS supports only PostgreSQL 16 and newer. DATABASE_URL points to a database of the type "%s".', $platform::class));

        // the image pgvector/pgvector:pg17 ships the extension; on another server a superuser installs it once (README, «База знаний»)
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        $this->addSql('CREATE TABLE itk_kb_document (id VARCHAR(64) NOT NULL, kind VARCHAR(8) NOT NULL, source_id VARCHAR(36) NOT NULL, locale VARCHAR(8) NOT NULL, resource_key VARCHAR(64) NOT NULL, title TEXT NOT NULL, url VARCHAR(255) DEFAULT NULL, space VARCHAR(120) NOT NULL, basis VARCHAR(40) NOT NULL, chunk_count INT NOT NULL, indexed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, page_translation_id VARCHAR(36) DEFAULT NULL, item_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT itk_kb_document_one_source CHECK ((kind = \'page\' AND page_translation_id IS NOT NULL AND item_id IS NULL) OR (kind = \'item\' AND item_id IS NOT NULL AND page_translation_id IS NULL)))');
        $this->addSql('CREATE UNIQUE INDEX itk_kb_document_source ON itk_kb_document (kind, source_id, locale)');
        $this->addSql('CREATE INDEX IDX_1CED33C2D301871 ON itk_kb_document (page_translation_id)');
        $this->addSql('CREATE INDEX IDX_1CED33C126F525E ON itk_kb_document (item_id)');
        $this->addSql('CREATE TABLE itk_kb_chunk (ordinal SMALLINT NOT NULL, kind VARCHAR(8) NOT NULL, locale VARCHAR(8) NOT NULL, resource_key VARCHAR(64) NOT NULL, space VARCHAR(120) NOT NULL, section_id VARCHAR(36) DEFAULT NULL, section_type VARCHAR(64) DEFAULT NULL, field VARCHAR(255) NOT NULL, heading TEXT DEFAULT NULL, text TEXT NOT NULL, input_hash VARCHAR(64) NOT NULL, embedding vector(1536) NOT NULL, document_id VARCHAR(64) NOT NULL, PRIMARY KEY (document_id, ordinal))');
        // cosine distance (<=>); m / ef_construction at pgvector's defaults (16 / 64), enough for a site of tens of thousands of chunks
        $this->addSql('CREATE INDEX itk_kb_chunk_embedding_idx ON itk_kb_chunk USING hnsw (embedding vector_cosine_ops)');
        $this->addSql('CREATE INDEX itk_kb_chunk_reuse_idx ON itk_kb_chunk (space, input_hash)');
        $this->addSql('CREATE INDEX itk_kb_chunk_filter_idx ON itk_kb_chunk (space, locale, kind, resource_key)');
        $this->addSql('CREATE INDEX IDX_3B6D1D97C33F7837 ON itk_kb_chunk (document_id)');
        $this->addSql('ALTER TABLE itk_kb_document ADD CONSTRAINT FK_1CED33C2D301871 FOREIGN KEY (page_translation_id) REFERENCES itk_page_translation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE itk_kb_document ADD CONSTRAINT FK_1CED33C126F525E FOREIGN KEY (item_id) REFERENCES itk_collection_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE itk_kb_chunk ADD CONSTRAINT FK_3B6D1D97C33F7837 FOREIGN KEY (document_id) REFERENCES itk_kb_document (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE itk_kb_chunk');
        $this->addSql('DROP TABLE itk_kb_document');
        // the extension stays: a site may use it for its own tables
    }
}

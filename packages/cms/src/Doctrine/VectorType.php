<?php

declare(strict_types=1);

namespace Itk\Cms\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Itk\Cms\KnowledgeBase\Embedding\Space;

/**
 * The pgvector column type, so that the ORM and the schema tools know itk_kb_chunk.embedding. Registered by the bundle
 * (doctrine.dbal.types.vector and mapping_types.vector) whether the knowledge base is on or off: without the mapping type the schema manager
 * stops at "Unknown database type vector" on every schema:validate and migrations:diff of the site.
 *
 * @internal
 */
final class VectorType extends Type
{
    public const NAME = 'vector';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'vector(' . Space::DIMENSIONS . ')';
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return [self::NAME];
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Itk\Cms\Entity\Page;
use Itk\Cms\Migrations\Version20261124090100;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageCreatedAtMappingTest extends KernelTestCase
{
    #[Test]
    public function creationDateHasAnExplicitColumnWithEitherNamingStrategy(): void
    {
        foreach ([new DefaultNamingStrategy(), new UnderscoreNamingStrategy()] as $strategy) {
            $metadata = new ClassMetadata(Page::class, $strategy);
            $metadata->initializeReflection(new \Doctrine\Persistence\Mapping\RuntimeReflectionService());
            (new AttributeDriver([]))->loadMetadataForClass(Page::class, $metadata);
            self::assertSame('created_at', $metadata->getColumnName('createdAt'));
        }
    }

    #[Test]
    public function migrationRetainsTheOriginalDateWithEveryLegacyColumnName(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $schema = 'f2_created_' . \bin2hex(\random_bytes(5));
        $searchPath = (string) $db->fetchOne('SHOW search_path');
        $db->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $db->executeStatement('SET search_path TO ' . $schema);
            $empty = new Version20261124090100($db, new NullLogger());
            $empty->up(new Schema());
            self::assertSame([], $empty->getSql(), 'an empty schema remains reviewable during dry runs');
            foreach (['createdAt', 'createdat', 'created_at'] as $column) {
                $db->executeStatement('CREATE TABLE itk_page (id TEXT PRIMARY KEY, "' . $column . '" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
                $db->executeStatement('INSERT INTO itk_page VALUES (?, ?)', ['original', '2024-02-03 04:05:06']);
                $migration = new Version20261124090100($db, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
                self::assertSame('2024-02-03 04:05:06', $db->fetchOne('SELECT created_at FROM itk_page WHERE id = ?', ['original']));
                $rollback = new Version20261124090100($db, new NullLogger());
                $rollback->down(new Schema());
                self::assertSame([], $rollback->getSql(), 'created_at already existed before 0.3.0');
                $db->executeStatement('DROP TABLE itk_page');
            }
        } finally {
            $db->executeStatement('SET search_path TO ' . $searchPath);
            $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }
}

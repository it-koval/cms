<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Migrations\Version20261124120100;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SeoPathMigrationTest extends KernelTestCase
{
    #[Test]
    public function legacyPathsBecomeCanonicalWithoutLosingQueriesRulesOrRedirects(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $schema = 'seo_paths_' . \bin2hex(\random_bytes(5));
        $previous = (string) $db->fetchOne('SHOW search_path');
        $db->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $db->executeStatement('SET search_path TO ' . $schema);
            $db->executeStatement('CREATE TABLE itk_seo_rule (LIKE public.itk_seo_rule INCLUDING ALL)');
            $db->executeStatement('CREATE TABLE itk_redirect (id TEXT, from_path TEXT, to_path TEXT)');
            $fixtures = [
                ['cyrillic', '/%d0%ba%d0%b0%d1%84%d0%b5', 'exact', null, '/кафе'],
                ['query', '/%63atalog?a=A%2FB&b=%23%3F%252F', 'exact', null, '/catalog?a=A%2FB&b=%23%3F%252F'],
                ['delimiters', '/x%2fy%3fz%23%5c%25%20%00', 'exact', null, '/x%2fy%3fz%23%5c%25%20%00'],
                ['sub-delimiters', '/x%3by%3dz%2b%26%40%3a', 'exact', null, '/x%3by%3dz%2b%26%40%3a'],
                ['double', '/%2561', 'exact', null, '/%2561'],
                ['invalid-utf8', '/%ff%61', 'exact', null, '/%ffa'],
                ['canonical', '/already', 'exact', null, '/already'],
                ['collision-a', '/%61', 'exact', null, '/%61'],
                ['collision-b', '/a', 'exact', null, '/a'],
                ['collision-c', '/c%61fe', 'exact', null, '/c%61fe'],
                ['collision-d', '/%63afe', 'exact', null, '/%63afe'],
                ['other-match', '/%61', 'prefix', null, '/a'],
                ['other-locale', '/%61', 'exact', 'kk', '/a'],
                ['other-query', '/%61?q=A', 'exact', null, '/a?q=A'],
            ];
            foreach ($fixtures as [$id, $path, $match, $locale]) {
                $db->insert('itk_seo_rule', [
                    'id' => $id, 'path' => $path, 'match_type' => $match, 'locale' => $locale,
                    'title' => $id, 'description' => '', 'robots' => '', 'canonical' => '', 'og_image' => '',
                    'active' => 'collision-b' !== $id ? 'true' : 'false',
                    'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00',
                ]);
            }
            // v0.2.2 RedirectService used Url::key() without percent decoding, just as its lookup still does.
            $db->insert('itk_redirect', ['id' => 'legacy', 'from_path' => '/%61?x=a%2fb', 'to_path' => '/Target?x=A%2FB']);
            $before = $db->fetchAllAssociative('SELECT * FROM itk_seo_rule ORDER BY id');
            $redirects = $db->fetchAllAssociative('SELECT * FROM itk_redirect');
            $logger = new class extends AbstractLogger {
                /** @var list<string> */
                public array $warnings = [];

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    if ('warning' === $level) {
                        $this->warnings[] = (string) $message;
                    }
                }
            };
            $migration = new Version20261124120100($db, $logger);
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
            foreach ($fixtures as [$id, , , , $expected]) {
                self::assertSame($expected, $db->fetchOne('SELECT path FROM itk_seo_rule WHERE id = ?', [$id]), $id);
            }
            $after = $db->fetchAllAssociative('SELECT * FROM itk_seo_rule ORDER BY id');
            foreach ($before as $i => $row) {
                unset($row['path'], $after[$i]['path']);
                self::assertSame($row, $after[$i], 'only path may change');
            }
            self::assertSame($redirects, $db->fetchAllAssociative('SELECT * FROM itk_redirect'));
            self::assertCount(2, $logger->warnings);
            self::assertStringContainsString('collision-a, collision-b', $logger->warnings[0]);
            self::assertStringContainsString('collision-c, collision-d', $logger->warnings[1]);
            self::assertStringContainsString('preserved unchanged', $logger->warnings[0]);
            self::assertStringContainsString('Settings > SEO', $logger->warnings[0]);
            $rule = self::getContainer()->get(SeoResolver::class)->forRequest(Request::create('/%D0%BA%D0%B0%D1%84%D0%B5'));
            self::assertSame('cyrillic', $rule?->id, 'migrated old-format rows match public requests');
            $again = new Version20261124120100($db, $logger);
            $again->up(new Schema());
            self::assertSame([], $again->getSql(), 'canonical paths and unresolved collisions stay unchanged on rerun');
        } finally {
            $db->executeStatement('SET search_path TO ' . $previous);
            $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }

    #[Test]
    public function currentAdminWritesUseTheSameSelectivePathTransform(): void
    {
        foreach (['/%D0%9A%D0%90%D0%A4%D0%95/', '/%61/%2F%3F%23%5C%25', '/%2561', '/%FF%61'] as $path) {
            self::assertSame(SeoRuleService::requestPathKey($path), SeoRuleService::normalizePath($path));
        }
        self::assertSame('/x%3by%3dz', SeoRuleService::normalizePath('/x%3By%3Dz'));
    }
}

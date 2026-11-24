<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Itk\Cms\Migrations\Version20261124090000;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class F2SettingsMigrationTest extends KernelTestCase
{
    #[Test]
    public function formsCopiesTheEffectiveLegacyBooleanAndPreservesItsSource(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $schema = 'legacy_forms_' . \bin2hex(\random_bytes(5));
        $previous = (string) $db->fetchOne('SHOW search_path');
        $db->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $db->executeStatement('SET search_path TO ' . $schema);
            $db->executeStatement('CREATE TABLE itk_setting (setting_key TEXT, locale TEXT, setting_value JSONB, PRIMARY KEY (setting_key, locale))');
            // v0.2.2 acceptsRequests() casts every non-null stored value with PHP's bool semantics.
            foreach ([false, true, 0, 1, 0.5, '', '0', '1', 'false', 'Site text', null, [], [false], (object) [], ['src' => '/site.png']] as $value) {
                $db->executeStatement('DELETE FROM itk_setting');
                $db->insert('itk_setting', ['setting_key' => 'forms', 'locale' => '*', 'setting_value' => \json_encode($value, \JSON_THROW_ON_ERROR)]);
                $source = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['forms']);
                // Doctrine decoded legacy JSON objects to associative arrays before applying the cast.
                $decoded = \json_decode(\json_encode($value, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
                $expected = (bool) ($decoded ?? true);
                $this->migrate($db);
                $this->migrate($db);
                self::assertSame(\json_encode($expected), $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['itk:forms']), 'effective legacy forms flag: ' . $source);
                self::assertSame($source, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['forms']));
                self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_setting'));
            }
        } finally {
            $db->executeStatement('SET search_path TO ' . $previous);
            $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }

    #[Test]
    public function numericFormRoutesAreCopiedAndExistingPrefixedRoutesRemainUntouched(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $schema = 'numeric_routes_' . \bin2hex(\random_bytes(5));
        $previous = (string) $db->fetchOne('SHOW search_path');
        $db->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $db->executeStatement('SET search_path TO ' . $schema);
            $db->executeStatement('CREATE TABLE itk_setting (setting_key TEXT, locale TEXT, setting_value JSONB, PRIMARY KEY (setting_key, locale))');
            foreach (['tgRoutes', 'maxRoutes', 'mailRoutes'] as $key) {
                $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => '["12345"]']);
            }
            $this->migrate($db);
            foreach (['tgRoutes', 'maxRoutes', 'mailRoutes'] as $key) {
                self::assertSame('["12345"]', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['itk:' . $key]), $key . ' routes for form key "0" survive the upgrade');
                self::assertSame('["12345"]', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', [$key]), 'ambiguous routes remain available to the site');
                $db->executeStatement('UPDATE itk_setting SET setting_value = ? WHERE setting_key = ?', ['["legacy"]', $key]);
            }
            $this->migrate($db);
            foreach (['tgRoutes', 'maxRoutes', 'mailRoutes'] as $key) {
                self::assertSame('["12345"]', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['itk:' . $key]));
                self::assertSame('["legacy"]', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', [$key]));
            }
        } finally {
            $db->executeStatement('SET search_path TO ' . $previous);
            $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }

    #[Test]
    public function allLegacyRowsAreCopiedWithoutTouchingTheSource(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $schema = 'f2_settings_' . \bin2hex(\random_bytes(5));
        $previous = (string) $db->fetchOne('SHOW search_path');
        $db->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $db->executeStatement('SET search_path TO ' . $schema);
            $db->executeStatement('CREATE TABLE itk_setting (setting_key TEXT, locale TEXT, setting_value JSONB, PRIMARY KEY (setting_key, locale))');
            $cases = [
                ['tgOn', true], ['maxOn', false], ['mcpEnabled', false], ['forms', false],
                ['tgRoutes', ['*' => '-10012345']], ['maxRoutes', ['*' => '-23']], ['mailRoutes', ['*' => 'site@example.test']],
                ['mail', ['host' => 'smtp.example.test']],
                ['mailStatus', ['okAt' => null, 'error' => null, 'errorAt' => null, 'kind' => null]],
                ['telegramBot', ['token' => 'sealed-marker']], ['maxBot', ['token' => 'sealed-marker']],
                ['yandexWebmaster', ['token' => 'sealed-marker', 'uin' => 'verification']],
                ['adminTheme', ['brand' => '#123456', 'accent' => '#654321', 'radius' => 6]],
                ['yandexWebmaster', ['uin' => 'verification']],
            ];
            foreach (['tgOn', 'maxOn', 'mcpEnabled'] as $key) {
                foreach ([false, true, 0, 1, 0.5, '', '0', '1', 'false', 'Site text', null, [], (object) [], [false], ['src' => '/site.png']] as $value) {
                    $cases[] = [$key, $value];
                }
            }
            foreach (['mail', 'mailStatus', 'adminTheme', 'telegramBot', 'maxBot', 'yandexWebmaster'] as $key) {
                foreach (['site string', 42, null, [], (object) [], true, false, ['src' => '/uploads/site.png', 'alt' => 'Site media']] as $value) {
                    $cases[] = [$key, $value];
                }
            }
            foreach ($cases as [$key, $value]) {
                $db->executeStatement('DELETE FROM itk_setting');
                $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => \json_encode($value, \JSON_THROW_ON_ERROR)]);
                $expected = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', [$key]);
                $this->migrate($db);
                $label = $key . ' shape ' . \json_encode($value);
                self::assertTrue(false !== $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['itk:' . $key, '*']), $label);
                self::assertTrue(false !== $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, '*']), $label);
                $decoded = \json_decode($expected, true, flags: \JSON_THROW_ON_ERROR);
                $copy = \in_array($key, ['tgOn', 'maxOn', 'forms'], true) ? \json_encode((bool) ($decoded ?? true)) : $expected;
                self::assertSame($copy, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['itk:' . $key]), 'the internal payload is retained: ' . $label);
                self::assertSame($expected, $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', [$key]), 'the site payload is retained: ' . $label);
                $this->migrate($db);
                self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_setting'), 'a repeated upgrade is idempotent: ' . $label);
            }
            $db->executeStatement('DELETE FROM itk_setting');
            foreach (['mail', 'itk:mail'] as $key) {
                $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => '{"host":"' . $key . '"}']);
            }
            $db->insert('itk_setting', ['setting_key' => 'maxOn', 'locale' => 'en', 'setting_value' => 'false']);
            $db->insert('itk_setting', ['setting_key' => 'forms', 'locale' => '*', 'setting_value' => 'false']);
            $db->insert('itk_setting', ['setting_key' => 'itk:forms', 'locale' => '*', 'setting_value' => 'true']);
            $this->migrate($db);
            self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_setting'));
            self::assertSame('mail', \json_decode($db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['mail', '*']), true)['host']);
            self::assertSame('false', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['maxOn', 'en']));
            self::assertSame('false', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['forms']));
            self::assertSame('true', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ?', ['itk:forms']));
            $down = new Version20261124090000($db, new NullLogger());
            $down->down(new Schema());
            $this->execute($db, $down);
            self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_setting'), 'rollback preserves a colliding site value too');
        } finally {
            $db->executeStatement('SET search_path TO ' . $previous);
            $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
        }
    }

    #[Test]
    public function scalarRoutesRetainTheLegacyArrayCast(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            foreach (['tgRoutes', 'maxRoutes', 'mailRoutes'] as $key) {
                foreach ([null, false, true, 0, 1, '', '0', '12345', 'site@example.test'] as $value) {
                    $db->delete('itk_setting', ['setting_key' => $key, 'locale' => '*']);
                    $db->delete('itk_setting', ['setting_key' => 'itk:' . $key, 'locale' => '*']);
                    $source = \json_encode($value, \JSON_THROW_ON_ERROR);
                    $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => $source]);
                    $this->migrate($db);
                    $copied = $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['itk:' . $key, '*']);
                    self::assertNotFalse($copied, $key . ' ' . $source);
                    self::assertSame((array) ($value ?? []), \json_decode($copied, true, flags: \JSON_THROW_ON_ERROR), $key . ' ' . $source);
                    self::assertSame($value, \json_decode((string) $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, '*']), true, flags: \JSON_THROW_ON_ERROR));
                }
            }
        } finally {
            $db->rollBack();
        }
    }

    private function migrate(Connection $db): void
    {
        $migration = new Version20261124090000($db, new NullLogger());
        $migration->up(new Schema());
        $this->execute($db, $migration);
    }

    private function execute(Connection $db, Version20261124090000 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}

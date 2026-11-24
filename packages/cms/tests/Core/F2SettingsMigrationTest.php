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
    public function onlyInternalShapesMoveAndExistingDestinationsAndLocalesStayIntact(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $schema = 'f2_settings_' . \bin2hex(\random_bytes(5));
        $previous = (string) $db->fetchOne('SHOW search_path');
        $db->executeStatement('CREATE SCHEMA ' . $schema);
        try {
            $db->executeStatement('SET search_path TO ' . $schema);
            $db->executeStatement('CREATE TABLE itk_setting (setting_key TEXT, locale TEXT, setting_value JSONB, PRIMARY KEY (setting_key, locale))');
            $objectKeys = ['mail', 'mailStatus', 'adminTheme', 'telegramBot', 'maxBot', 'yandexWebmaster', 'tgRoutes', 'maxRoutes', 'mailRoutes'];
            $booleanKeys = ['mcpEnabled', 'tgOn', 'maxOn'];
            $keys = [...$objectKeys, ...$booleanKeys];
            foreach (['site string', 42, null, [], (object) [], true, false, ['host' => 'smtp.example.test']] as $value) {
                $db->executeStatement('DELETE FROM itk_setting');
                foreach ($keys as $key) {
                    $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => \json_encode($value, \JSON_THROW_ON_ERROR)]);
                }
                $this->migrate($db);
                foreach ($keys as $key) {
                    $moves = \in_array($key, $booleanKeys, true) ? \is_bool($value) : \is_object($value) || \is_array($value) && !\array_is_list($value);
                    self::assertSame($moves, false !== $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['itk:' . $key, '*']), $key . ' shape ' . \json_encode($value));
                    self::assertSame(!$moves, false !== $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, '*']));
                }
            }
            $db->executeStatement('DELETE FROM itk_setting');
            foreach (['mail', 'itk:mail'] as $key) {
                $db->insert('itk_setting', ['setting_key' => $key, 'locale' => '*', 'setting_value' => '{"host":"' . $key . '"}']);
            }
            $db->insert('itk_setting', ['setting_key' => 'maxOn', 'locale' => 'en', 'setting_value' => 'false']);
            $this->migrate($db);
            self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_setting'));
            self::assertSame('mail', \json_decode($db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['mail', '*']), true)['host']);
            self::assertSame('false', $db->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', ['maxOn', 'en']));
            $down = new Version20261124090000($db, new NullLogger());
            $down->down(new Schema());
            $this->execute($db, $down);
            self::assertSame(3, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_setting'), 'rollback preserves a colliding site value too');
        } finally {
            $db->executeStatement('SET search_path TO ' . $previous);
            $db->executeStatement('DROP SCHEMA ' . $schema . ' CASCADE');
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

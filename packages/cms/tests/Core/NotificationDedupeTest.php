<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Notification\Channel\DatabaseChannel;
use Itk\Cms\Notification\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class NotificationDedupeTest extends KernelTestCase
{
    #[Test]
    public function concurrentNotificationsWithTheSameKeyAreStoredOnce(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $prefix = 'f2-dedupe-' . \bin2hex(\random_bytes(5));
        $script = <<<'PHP'
require 'tests/bootstrap.php';
$kernel = new Itk\Cms\Tests\App\TestKernel('test', true);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$c->get(Doctrine\DBAL\Connection::class)->executeQuery("SELECT set_config('application_name', ?, false)", [$argv[1]]);
$c->get(Itk\Cms\Notification\Channel\DatabaseChannel::class)->send(new Itk\Cms\Notification\Notification($argv[1], 'F2 notification', key: $argv[1]));
$kernel->shutdown();
PHP;
        $workers = [
            new Process([\PHP_BINARY, '-r', $script, $prefix], \dirname(__DIR__, 2), timeout: 15),
            new Process([\PHP_BINARY, '-r', $script, $prefix], \dirname(__DIR__, 2), timeout: 15),
        ];
        try {
            $db->beginTransaction();
            $db->executeStatement('LOCK TABLE itk_notification IN SHARE MODE');
            foreach ($workers as $worker) {
                $worker->start();
            }
            $waiting = 0;
            $deadline = \microtime(true) + 10;
            do {
                $db->executeQuery('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM pg_locks l JOIN pg_stat_activity a ON l.pid = a.pid WHERE a.application_name = ? AND l.relation = 'itk_notification'::regclass AND l.mode = 'RowExclusiveLock' AND NOT l.granted",
                    [$prefix],
                );
                if (2 === $waiting) {
                    break;
                }
                \usleep(10000);
            } while (\microtime(true) < $deadline);
            $db->commit();
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }

            self::assertSame(2, $waiting, 'both inserts were waiting before either could commit');
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_notification WHERE type = ?', [$prefix]));

            $channel = self::getContainer()->get(DatabaseChannel::class);
            $channel->send(new Notification($prefix, 'Other key', key: $prefix . '-other'));
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_notification WHERE type = ?', [$prefix]));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            foreach ($workers as $worker) {
                $worker->stop();
            }
            $db->delete('itk_notification', ['type' => $prefix]);
        }
    }
}

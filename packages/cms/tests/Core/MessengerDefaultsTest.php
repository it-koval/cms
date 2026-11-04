<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A clean project has no messenger transports, yet the bundle routes four messages to `async`: the bundle gives the site `async` and `failed` on its own database
 * (doctrine://default), unless the site configured them. A site's own transports win, key by key.
 */
final class MessengerDefaultsTest extends KernelTestCase
{
    private const CLEAN = ['messenger' => ['buses' => ['messenger.bus.default' => null]]];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, null, '', $options['framework'] ?? []);
    }

    /** @return object the transport the worker would consume */
    private function transport(string $name): object
    {
        return self::getContainer()->get('messenger.receiver_locator')->get($name);
    }

    #[Test]
    public function aCleanProjectGetsAsyncAndFailedOnItsDatabase(): void
    {
        self::bootKernel(['framework' => self::CLEAN]);

        self::assertInstanceOf(DoctrineTransport::class, $this->transport('async'));
        self::assertInstanceOf(DoctrineTransport::class, $this->transport('failed'));
        self::assertTrue(self::getContainer()->get(MessengerMonitor::class)->failed()['available'], 'the failure transport is on');
        self::assertNotSame($this->transport('async'), $this->transport('failed'));
    }

    #[Test]
    public function theFourMessagesOfTheBundleAreQueuedInTheDatabaseOfACleanProject(): void
    {
        self::bootKernel(['framework' => self::CLEAN]);
        $db = self::getContainer()->get(Connection::class);
        $before = (int) $db->fetchOne("SELECT COALESCE(MAX(id), 0) FROM messenger_messages WHERE queue_name = 'default'");

        self::getContainer()->get('messenger.bus.default')->dispatch(new SendRequestNotification(1));

        try {
            self::assertSame(1, (int) $db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'default' AND id > ?", [$before]), 'the message waits for a worker');
        } finally {
            $db->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'default' AND id > ?", [$before]);
        }
    }

    #[Test]
    public function aSiteThatConfiguredItsTransportsKeepsThem(): void
    {
        self::bootKernel(); // the test site: async in memory, failed with auto_setup=0

        self::assertInstanceOf(InMemoryTransport::class, $this->transport('async'));
        self::assertInstanceOf(DoctrineTransport::class, $this->transport('failed'));
    }

    #[Test]
    public function aSiteWithItsOwnFailureTransportGetsNoSecondOne(): void
    {
        self::bootKernel(['framework' => ['messenger' => [
            'failure_transport' => 'dead',
            'buses' => ['messenger.bus.default' => null],
            'transports' => [
                'async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 7]],
                'dead' => 'in-memory://',
            ],
        ]]]);

        self::assertInstanceOf(InMemoryTransport::class, $this->transport('async'));
        self::assertFalse(self::getContainer()->get('messenger.receiver_locator')->has('failed'), 'the bundle adds no «failed» next to the site\'s «dead»');
    }

    #[Test]
    public function aSiteThatConfiguredOnlyItsAsyncStillGetsAFailureTransport(): void
    {
        self::bootKernel(['framework' => ['messenger' => [
            'buses' => ['messenger.bus.default' => null],
            'transports' => ['async' => 'in-memory://'],
        ]]]);

        self::assertInstanceOf(InMemoryTransport::class, $this->transport('async'));
        self::assertTrue(self::getContainer()->get(MessengerMonitor::class)->failed()['available']);
        self::assertInstanceOf(DoctrineTransport::class, $this->transport('failed'));
    }

    #[Test]
    public function installSetsUpTheTransportsThatCanBeSetUp(): void
    {
        $kernel = self::bootKernel(['framework' => self::CLEAN]);
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('DROP TABLE IF EXISTS messenger_messages');

        try {
            $install = new CommandTester((new Application($kernel))->find('itk:install'));
            self::assertSame(0, $install->execute(['--no-home' => true], ['interactive' => false]), $install->getDisplay());

            self::assertTrue($db->createSchemaManager()->tablesExist(['messenger_messages']), 'the table of the queue is there before the first message');
            self::assertStringContainsString('Очередь сообщений: подготовлено транспортов — 2', $install->getDisplay());
        } finally {
            $this->transport('failed')->setup(); // the test site\'s «failed» has auto_setup=0: leave the table as the other tests expect it
        }
    }
}

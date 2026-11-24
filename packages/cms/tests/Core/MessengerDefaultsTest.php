<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\CriticalCss\Model\GenerateCriticalCss;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A clean project has no messenger transports, yet the bundle routes four messages to `async`: the bundle gives the site `async` and `failed` on its own database
 * (doctrine://default), unless the site configured them. A site's own transports win, key by key.
 */
final class MessengerDefaultsTest extends KernelTestCase
{
    private const CLEAN = ['messenger' => ['buses' => ['messenger.bus.default' => null]]];

    /** @param array<string, mixed> $options */
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
        self::assertInstanceOf(DoctrineTransport::class, $this->transport('critical_css'));
        self::assertTrue(self::getContainer()->get(MessengerMonitor::class)->failed()['available'], 'the failure transport is on');
        self::assertNotSame($this->transport('async'), $this->transport('failed'));
        self::assertNotSame($this->transport('async'), $this->transport('critical_css'));
    }

    #[Test]
    public function theCriticalCssMessageWaitsInItsOwnQueueOfACleanProject(): void
    {
        self::bootKernel(['framework' => self::CLEAN]);
        $db = self::getContainer()->get(Connection::class);
        $before = (int) $db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM messenger_messages');

        self::getContainer()->get('messenger.bus.default')->dispatch(new GenerateCriticalCss('no-such-version'));

        try {
            self::assertSame(['critical_css'], $db->fetchFirstColumn('SELECT queue_name FROM messenger_messages WHERE id > ?', [$before]), 'letters never wait behind Chrome');
        } finally {
            $db->executeStatement('DELETE FROM messenger_messages WHERE id > ?', [$before]);
        }
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
    public function requestDeliveryRetriesThreeTimesThenGoesToFailed(): void
    {
        self::bootKernel(['framework' => self::CLEAN]);
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();

        try {
            $id = \random_int(1_000_000_000, 2_000_000_000);
            $failed = $this->transport('failed');
            self::assertInstanceOf(DoctrineTransport::class, $failed);
            $before = $failed->getMessageCount();
            self::assertSame([60000, 120000, 240000, null], $this->failureDelays(new SendRequestNotification($id), 4));
            self::assertSame($before + 1, $failed->getMessageCount());

            $otherDelay = $this->failureDelays(new GenerateCriticalCss('f1-' . $id), 1)[0];
            self::assertGreaterThanOrEqual(900, $otherDelay);
            self::assertLessThanOrEqual(1100, $otherDelay);
        } finally {
            $db->rollBack();
        }
    }

    #[Test]
    public function requestDeliveryRespectsTheSitesRetrySettings(): void
    {
        self::bootKernel(['framework' => ['messenger' => [
            'buses' => ['messenger.bus.default' => null],
            'failure_transport' => 'failed',
            'transports' => [
                'async' => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 1, 'delay' => 7000, 'jitter' => 0]],
                'failed' => 'in-memory://',
            ],
        ]]]);

        self::assertSame([7000, null], $this->failureDelays(new SendRequestNotification(\random_int(1_000_000_000, 2_000_000_000)), 2));
    }

    /** @return list<int|null> one delay per simulated worker failure */
    private function failureDelays(object $message, int $attempts): array
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $delay = null;
        $listener = static function (WorkerMessageRetriedEvent $event) use (&$delay): void {
            $delay = $event->getEnvelope()->last(DelayStamp::class)?->getDelay();
        };
        $dispatcher->addListener(WorkerMessageRetriedEvent::class, $listener);

        try {
            $delays = [];
            for ($attempt = 0; $attempt < $attempts; ++$attempt) {
                $delay = null;
                $envelope = new Envelope($message, [new RedeliveryStamp($attempt)]);
                $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('Synthetic delivery failure'));
                $dispatcher->dispatch($event);
                // @phpstan-ignore notIdentical.alwaysFalse (The listener sets $delay through a reference.)
                self::assertSame(null !== $delay, $event->willRetry());
                $delays[] = $delay;
            }

            return $delays;
        } finally {
            $dispatcher->removeListener(WorkerMessageRetriedEvent::class, $listener);
        }
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
        $db->beginTransaction();

        try {
            $db->executeStatement('DROP TABLE IF EXISTS messenger_messages');
            $install = new CommandTester((new Application($kernel))->find('itk:install'));
            self::assertSame(0, $install->execute(['--no-home' => true], ['interactive' => false]), $install->getDisplay());

            self::assertTrue($db->createSchemaManager()->tablesExist(['messenger_messages']), 'the table of the queue is there before the first message');
            self::assertStringContainsString('Очередь сообщений: подготовлено транспортов — 4', $install->getDisplay());
        } finally {
            $db->rollBack();
        }
    }
}

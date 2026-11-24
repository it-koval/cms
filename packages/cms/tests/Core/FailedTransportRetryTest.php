<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Request\RequestDeliverer;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\MessengerApi;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class FailedTransportRetryTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: ['schema_file' => __DIR__ . '/Fixture/schema_long_requests.php']);
    }

    #[Test]
    public function theMonitorRequeuesTheCurrentFailedJobAndAcknowledgesAStaleOne(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $c->get(Connection::class);
        $failed = $c->get('messenger.transport.failed');
        $async = $c->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $monitor = $c->get(MessengerMonitor::class);
        $request = new Request('long_text');
        $em->persist($request);
        $em->flush();
        $requestId = (int) $request->id;

        $ids = [];
        try {
            $job = RequestDeliverer::reserve($em, $requestId, Request::CHANNEL_TELEGRAM, true);
            self::assertNotNull($job);
            $c->get(RequestDeliverer::class)->queueFailed($requestId, Request::CHANNEL_TELEGRAM, $job, 'terminal provider refusal');
            $current = new SendRequestNotification($requestId, true, Request::CHANNEL_TELEGRAM, $job);
            $stale = new SendRequestNotification($requestId, true, Request::CHANNEL_TELEGRAM, 'superseded');
            foreach ([$current, $stale] as $message) {
                $envelope = $failed->send(new Envelope($message, [new SentToFailureTransportStamp('async')]));
                $id = (string) $envelope->last(TransportMessageIdStamp::class)->getId();
                $ids[] = $id;
                $monitor->retry($id);
                self::assertNull($failed->find($id));
            }

            $em->refresh($request);
            self::assertSame('queued', $request->channelJobPhase(Request::CHANNEL_TELEGRAM));
            self::assertSame($job, $request->channelJob(Request::CHANNEL_TELEGRAM));
            $sent = $async->getSent();
            self::assertCount(1, $sent);
            self::assertEquals($current, $sent[0]->getMessage());
        } finally {
            foreach ($ids as $id) {
                $db->executeStatement('DELETE FROM messenger_messages WHERE id = ?', [$id]);
            }
            $db->executeStatement('DELETE FROM itk_request WHERE id = ?', [$requestId]);
        }
    }

    #[Test]
    public function officialRetryDeliversTheCurrentFailedJobAndIgnoresCompletedOrSupersededJobs(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $c->get(Connection::class);
        $settings = $c->get(SettingsService::class);
        $saved = [];
        foreach (['tgOn' => true, 'tgRoutes' => ['long_text' => '-1001234567890']] as $key => $value) {
            $saved[$key] = [$settings->isSet($key), $settings->get($key)];
            $settings->set($key, $value);
        }
        $api = new MessengerApi();
        $c->set(TelegramNotifier::class, new TelegramNotifier($c->get(Schema::class), $settings, new NullLogger(), static fn () => $api, $c->get('event_dispatcher'), true, $api->sleep(...)));
        $r = new Request('long_text');
        $r->data = ['items' => 'official failed transport retry'];
        $em->persist($r);
        $em->flush();
        $ids = [];
        try {
            $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', true);
            $message = new SendRequestNotification((int) $r->id, true, 'telegram', $job);
            $deliverer = $c->get(RequestDeliverer::class);
            $deliverer->queueFailed((int) $r->id, 'telegram', $job, 'terminal provider refusal');
            $failed = $c->get('messenger.transport.failed');
            $command = new CommandTester((new Application(self::$kernel))->find('messenger:failed:retry'));
            $retry = static function () use ($failed, $message, $command, &$ids): void {
                $envelope = $failed->send(new Envelope($message, [new SentToFailureTransportStamp('async')]));
                $id = (string) $envelope->last(TransportMessageIdStamp::class)->getId();
                $ids[] = $id;
                self::assertSame(0, $command->execute(['id' => [$id], '--force' => true], ['interactive' => false]));
                self::assertNull($failed->find($id), 'the official command acknowledges the retried envelope');
            };
            $retry();
            self::assertCount(1, $api->texts('telegram'), 'a failed job must be delivered before its envelope is acknowledged');
            $r = $em->find(Request::class, $r->id);
            self::assertSame('done', $r->channelJobPhase('telegram'));
            $retry();
            self::assertCount(1, $api->texts('telegram'), 'completed tokens remain closed');
            $newJob = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', true);
            self::assertNotSame($job, $newJob);
            $retry();
            self::assertCount(1, $api->texts('telegram'), 'superseded tokens cannot send again');
            $r = $em->find(Request::class, $r->id);
            self::assertSame($newJob, $r->channelJob('telegram'));
            self::assertSame('queued', $r->channelJobPhase('telegram'));
        } finally {
            foreach ($ids as $id) {
                $db->executeStatement('DELETE FROM messenger_messages WHERE id = ?', [$id]);
            }
            $db->executeStatement('DELETE FROM itk_notification WHERE dedupe_key = ?', ['telegram.failed:' . $r->id]);
            $db->executeStatement('DELETE FROM itk_request WHERE id = ?', [$r->id]);
            foreach ($saved as $key => [$existed, $value]) {
                $existed ? $settings->set($key, $value) : $settings->remove($key);
            }
        }
    }
}

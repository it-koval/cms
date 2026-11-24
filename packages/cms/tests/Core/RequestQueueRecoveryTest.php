<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Notification\Channel\TelegramChannel;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Request\RequestQueue;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as QueueConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Event\MessageSentToTransportsEvent;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;

/** The broker uses another PostgreSQL connection: it can accept a message before the request transaction commits. */
final class RequestQueueRecoveryTest extends KernelTestCase
{
    private Connection $broker;
    private DoctrineTransport $transport;
    private EventDispatcher $events;
    private MessageBus $bus;
    private bool $telegramWasSet;
    private mixed $telegramWas;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $this->broker = DriverManager::getConnection($db->getParams(), $db->getConfiguration());
        $this->transport = new DoctrineTransport(new QueueConnection(['queue_name' => 'request_outbox_test', 'auto_setup' => false], $this->broker), new PhpSerializer());
        $this->events = new EventDispatcher();
        $this->bus = new MessageBus([new SendMessageMiddleware(new SendersLocator(['*' => ['test']], new ServiceLocator(['test' => fn () => $this->transport])), $this->events)]);
        $settings = self::getContainer()->get(SettingsService::class);
        $this->telegramWasSet = $settings->isSet('tgOn');
        $this->telegramWas = $settings->get('tgOn');
        $settings->set('tgOn', true);
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(Connection::class);
        while ($db->isTransactionActive()) {
            $db->rollBack();
        }
        $db->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'request_outbox_test'");
        $db->executeStatement("DELETE FROM itk_request WHERE form_key = 'queue_recovery_test'");
        $settings = self::getContainer()->get(SettingsService::class);
        if ($this->telegramWasSet) {
            $settings->set('tgOn', $this->telegramWas);
        } else {
            $settings->remove('tgOn');
        }
        $this->broker->close();
        parent::tearDown();
    }

    private function channel(): TelegramChannel
    {
        $c = self::getContainer();
        $telegram = new TelegramNotifier($c->get(Schema::class), $c->get(SettingsService::class), new NullLogger(), static fn (): TransportInterface => new TelegramTransport('123456:test-token'), $c->get('event_dispatcher'), true);

        return new TelegramChannel($c->get(EntityManagerInterface::class), $this->bus, $c->get('event_dispatcher'), new NullLogger(), $telegram);
    }

    private function request(): Request
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $request = new Request('queue_recovery_test');
        $em->persist($request);
        $em->flush();

        return $request;
    }

    private function notify(Request $request): void
    {
        $this->channel()->send(new Notification(Notification::REQUEST_NEW, 'test', payload: ['requestId' => $request->id]));
    }

    #[Test]
    public function anUncommittedSubmissionCannotEscapeToTheBrokerAndIsRecoveredAfterCommit(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        $request = $this->request();
        $this->notify($request);
        self::assertSame(0, $this->transport->getMessageCount(), 'an early worker must not consume and acknowledge an invisible request');
        $db->commit();
        $queue = new RequestQueue(self::getContainer()->get(EntityManagerInterface::class), $this->bus, new NullLogger());
        self::assertSame(1, $queue->recover());
        $messages = \iterator_to_array($this->transport->get());
        self::assertCount(1, $messages);
        self::assertSame($request->id, $messages[0]->getMessage()->requestId);
        $this->transport->ack($messages[0]);
    }

    #[Test]
    public function acceptedThenThrowingPublicationKeepsTheOriginalJobRecoverable(): void
    {
        $request = $this->request();
        $this->events->addListener(MessageSentToTransportsEvent::class, static function (): never {
            throw new \RuntimeException('after broker acceptance');
        });
        $this->notify($request);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($request);
        self::assertNotSame('failed', $request->channelJobPhase('telegram'), 'an accepted envelope must not be invalidated on an ambiguous exception');
        $accepted = \iterator_to_array($this->transport->get());
        self::assertCount(1, $accepted);
        $originalJob = $accepted[0]->getMessage()->jobId;
        $this->transport->ack($accepted[0]);
        $this->events->removeListener(MessageSentToTransportsEvent::class, $this->events->getListeners(MessageSentToTransportsEvent::class)[0]);
        $em->getConnection()->executeStatement('UPDATE itk_request_queue SET available_at = 0 WHERE request_id = ?', [$request->id]);
        $queue = new RequestQueue($em, $this->bus, new NullLogger());
        self::assertSame(1, $queue->recover());
        $retry = \iterator_to_array($this->transport->get());
        self::assertCount(1, $retry);
        self::assertSame($originalJob, $retry[0]->getMessage()->jobId, 'the durable intent retains the accepted job identity');
        $this->transport->ack($retry[0]);
    }

    #[Test]
    public function rollingBackSubmissionRemovesItsQueueIntent(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        $request = $this->request();
        $id = $request->id;
        $this->notify($request);
        $db->rollBack();
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(0, $this->transport->getMessageCount(), 'rolling back must remove every externally deliverable message');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$id]));
    }

    #[Test]
    public function aProcessStoppingAfterReservationCannotStrandTheJob(): void
    {
        $request = $this->request();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $deliverer = self::getContainer()->get(\Itk\Cms\Request\RequestDeliverer::class);
        $job = $deliverer->reserveForDispatch((int) $request->id, 'telegram', false, static function (EntityManagerInterface $manager, string $job) use ($request): void {
            RequestQueue::stageJob($manager, new \Itk\Cms\Request\SendRequestNotification((int) $request->id, false, 'telegram', $job));
        });
        self::assertSame(0, $this->transport->getMessageCount(), 'the process stopped before calling dispatch');
        $queue = new RequestQueue($em, $this->bus, new NullLogger());
        self::assertSame(1, $queue->recover());
        $recovered = \iterator_to_array($this->transport->get());
        self::assertCount(1, $recovered);
        self::assertSame($job, $recovered[0]->getMessage()->jobId);
        $this->transport->ack($recovered[0]);
    }

    #[Test]
    public function recoveryDoesNotRepublishAnIntentForASupersededOrCompletedJob(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        $request = $this->request();
        $this->notify($request);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($request);
        $request->markChannelJob('telegram', (string) $request->channelJob('telegram'), 'done');
        $em->flush();
        $db->commit();
        $queue = new RequestQueue($em, $this->bus, new NullLogger());
        self::assertSame(0, $queue->recover());
        self::assertSame(0, $this->transport->getMessageCount());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$request->id]));
    }

    #[Test]
    public function aNewSubmissionCommitsBeforeAQueueFailureIsRecorded(): void
    {
        $this->events->addListener(MessageSentToTransportsEvent::class, static function (): never {
            throw new \RuntimeException('postgres://user:secret@example.invalid/db');
        });
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $telegram = new TelegramNotifier($c->get(Schema::class), $c->get(SettingsService::class), new NullLogger(), static fn (): TransportInterface => new TelegramTransport('123456:test-token'), $c->get('event_dispatcher'), true);
        $channel = $this->channel();
        $notifier = new class($channel) implements \Itk\Cms\Notification\NotifierInterface {
            public function __construct(private readonly TelegramChannel $channel)
            {
            }

            public function notify(Notification $notification, ?Connection $connection = null): void
            {
                $this->channel->send($notification);
            }
        };
        $service = new \Itk\Cms\Request\RequestService($em, $c->get(\Itk\Cms\Repository\RequestRepository::class), $c->get(Schema::class), $c->get(SettingsService::class), $telegram, $c->get(\Itk\Cms\Request\MaxNotifier::class), $c->get(\Itk\Cms\Request\RequestDeliverer::class), $notifier, $this->bus, 'test-secret', 'ru', new RequestQueue($em, $this->bus, new NullLogger()));
        $request = $service->submit('call', ['phone' => '+7(900)000-00-11']);
        try {
            self::assertSame(1, (int) $this->broker->fetchOne('SELECT COUNT(*) FROM itk_request WHERE id = ?', [$request->id]));
            $em->refresh($request);
            self::assertSame('failed', $request->channelState('telegram'));
            self::assertSame('queued', $request->channelJobPhase('telegram'));
            self::assertSame(1, (int) $this->broker->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$request->id]));
            self::assertStringNotContainsString('secret', (string) $request->channelError('telegram'));
        } finally {
            $em->getConnection()->executeStatement('DELETE FROM itk_request WHERE id = ?', [$request->id]);
        }
    }

    #[Test]
    public function oneFailedPublicationDoesNotPreventTheOtherCommittedChannel(): void
    {
        $request = $this->request();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->wrapInTransaction(static function () use ($em, $request): void {
            foreach (['telegram', 'max'] as $channel) {
                $job = \Itk\Cms\Request\RequestDeliverer::reserve($em, (int) $request->id, $channel, false);
                RequestQueue::stageJob($em, new \Itk\Cms\Request\SendRequestNotification((int) $request->id, false, $channel, $job));
            }
        });
        $this->events->addListener(\Symfony\Component\Messenger\Event\SendMessageToTransportsEvent::class, static function ($event): void {
            if ('telegram' === $event->getEnvelope()->getMessage()->channel) {
                throw new \RuntimeException('before broker send');
            }
        });
        $queue = new RequestQueue($em, $this->bus, new NullLogger());
        self::assertSame(1, $queue->recover());
        $accepted = \iterator_to_array($this->transport->get());
        self::assertCount(1, $accepted);
        self::assertSame('max', $accepted[0]->getMessage()->channel);
        $this->transport->ack($accepted[0]);
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$request->id]));
    }

    #[Test]
    public function aWorkerCanConsumeTheAcceptedEnvelopeAfterPublicationThrows(): void
    {
        $c = self::getContainer();
        $settings = $c->get(SettingsService::class);
        $routesWereSet = $settings->isSet('tgRoutes');
        $routesWere = $settings->get('tgRoutes');
        $settings->set('tgRoutes', ['*' => '-1001234567890']);
        $em = $c->get(EntityManagerInterface::class);
        $workerDb = DriverManager::getConnection($em->getConnection()->getParams(), $em->getConnection()->getConfiguration());
        try {
            $request = $this->request();
            $this->events->addListener(MessageSentToTransportsEvent::class, static function (): never {
                throw new \RuntimeException('accepted, then listener failed');
            });
            $this->notify($request);
            $accepted = \iterator_to_array($this->transport->get());
            self::assertCount(1, $accepted);
            $api = new \Itk\Cms\Tests\Core\Fixture\MessengerApi();
            $telegram = new TelegramNotifier($c->get(Schema::class), $settings, new NullLogger(), static fn (): TransportInterface => $api, $c->get('event_dispatcher'), true, $api->sleep(...));
            $workerEm = new \Doctrine\ORM\EntityManager($workerDb, $em->getConfiguration());
            $handler = new \Itk\Cms\Request\SendRequestNotificationHandler(new \Itk\Cms\Request\RequestDeliverer($workerEm, $telegram, $c->get(\Itk\Cms\Request\MaxNotifier::class), $c->get(\Itk\Cms\I18n\AdminLocale::class)));
            $workerBus = new MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([\Itk\Cms\Request\SendRequestNotification::class => [$handler]]))]);
            $workerBus->dispatch($accepted[0]);
            $this->transport->ack($accepted[0]);
            $workerBus->dispatch($accepted[0]);
            self::assertCount(1, $api->texts('telegram'), 'the accepted job is still valid, and a duplicate envelope cannot send twice');
            $em->refresh($request);
            self::assertSame('sent', $request->channelState('telegram'));
            self::assertSame('done', $request->channelJobPhase('telegram'));
            $em->getConnection()->executeStatement('UPDATE itk_request_queue SET available_at = 0 WHERE request_id = ?', [$request->id]);
            self::assertSame(0, (new RequestQueue($em, $this->bus, new NullLogger()))->recover());
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$request->id]));
        } finally {
            $workerDb->close();
            if ($routesWereSet) {
                $settings->set('tgRoutes', $routesWere);
            } else {
                $settings->remove('tgRoutes');
            }
        }
    }
}

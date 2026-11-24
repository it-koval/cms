<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request as RequestEntity;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Entity\User;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\Health\MaxHealthCheck;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Notification\Channel\MaxChannel;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Request\RequestDeliverer;
use Itk\Cms\Request\RequestNotDeliveredException;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Request\SendRequestNotificationHandler;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\FakeMaxSender;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\FailingMessageBus;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * MAX is a second messenger for the requests, next to Telegram: its own switch, chats, queue message, retries, bell and delivery state.
 * Whatever MAX does, the Telegram delivery of the same request stays as it was.
 */
final class MaxChannelTest extends WebTestCase
{
    private const CHAT = '-70123456789012';

    /** @var list<int> */
    private array $requestIds = [];
    /** @var array<string, array{bool, mixed}> */
    private array $saved = [];
    private string $csrf = '';

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, null, '', [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        if (null !== static::$kernel) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            foreach ($this->requestIds as $id) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.dedupeKey IN (:k)')->setParameter('k', ['max.failed:' . $id, 'telegram.failed:' . $id])->execute();
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id = :i')->setParameter('i', $id)->execute();
            }
            $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-max-%@example.com')->execute();
            $settings = self::getContainer()->get(SettingsService::class);
            foreach ($this->saved as $key => [$existed, $value]) {
                if ($existed) {
                    $settings->set($key, $value);
                } else {
                    $settings->remove($key);
                }
            }
        }
        parent::tearDown();
    }

    private function setting(string $key, mixed $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->saved[$key] ??= [$settings->isSet($key), $settings->get($key)];
        $settings->set($key, $value);
    }

    private function fake(bool $connected = true): FakeMaxSender
    {
        $fake = self::getContainer()->get(FakeMaxSender::class);
        $fake->connected = $connected;

        return $fake;
    }

    /** MAX is connected, on, and has a chat for the requests. */
    private function maxReady(): FakeMaxSender
    {
        $this->setting('maxRoutes', ['*' => self::CHAT]);
        $this->setting('maxOn', true);

        return $this->fake();
    }

    private function request(): RequestEntity
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = new RequestEntity('call');
        $r->phone = '+7(900)000-00-41';
        $r->data = ['phone' => '+7(900)000-00-41'];
        $em->persist($r);
        $em->flush();
        $this->requestIds[] = (int) $r->id;

        return $r;
    }

    private function max(): MaxNotifier
    {
        return self::getContainer()->get(MaxNotifier::class);
    }

    private function bell(RequestEntity $r, string $type): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(StoredNotification::class)->count(['dedupeKey' => $type . ':' . $r->id]);
    }

    private function channel(MessageBusInterface $bus): MaxChannel
    {
        return new MaxChannel(self::getContainer()->get(EntityManagerInterface::class), $bus, self::getContainer()->get('event_dispatcher'), new NullLogger(), $this->max());
    }

    private function newRequestNote(RequestEntity $r): Notification
    {
        return new Notification(Notification::REQUEST_NEW, 'Новая заявка', payload: ['requestId' => $r->id]);
    }

    /** A Telegram that takes (or refuses) every message. */
    private function telegram(bool $refuse = false): TelegramNotifier
    {
        $transport = new class($refuse) implements TransportInterface {
            public function __construct(private readonly bool $refuse)
            {
            }

            public function send(MessageInterface $message): SentMessage
            {
                if ($this->refuse) {
                    throw new \RuntimeException('Chat not found');
                }

                return new SentMessage($message, 'recorder');
            }

            public function supports(MessageInterface $message): bool
            {
                return $message instanceof ChatMessage;
            }

            public function __toString(): string
            {
                return 'recorder://default';
            }
        };

        return new TelegramNotifier(
            self::getContainer()->get(Schema::class),
            self::getContainer()->get(SettingsService::class),
            new NullLogger(),
            static fn (): TransportInterface => $transport,
            self::getContainer()->get('event_dispatcher'),
            true,
        );
    }

    private function signIn(): void
    {
        $client = static::getClient() ?? static::createClient();
        \assert($client instanceof KernelBrowser);
        $user = self::getContainer()->get(UserManager::class)->create('test-max-admin@example.com', 'Тест MAX', 'admin', 'password-for-test');
        \assert($user instanceof User);
        $client->loginUser($user, 'itk_admin');
        $client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $client->getResponse()->getContent(), $m);
        $this->csrf = $m[1];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $client = static::getClient();
        \assert($client instanceof KernelBrowser);
        $client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return (array) \json_decode((string) $client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $options */
    private function adminClient(array $options = []): void
    {
        $client = static::createClient($options);
        $client->disableReboot();
        $this->signIn();
    }

    /* ---------- the channel ---------- */

    #[Test]
    public function aSiteWithoutMaxDoesNotQueueAndDoesNotFailWhenTheQueueIsDown(): void
    {
        self::bootKernel();
        $this->setting('maxRoutes', ['*' => self::CHAT]);
        $this->fake(false);
        $r = $this->request();
        $bus = new FailingMessageBus();

        $this->channel($bus)->send($this->newRequestNote($r));

        self::assertSame(0, $bus->attempts, 'nothing to deliver: no message in the queue');
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->channelState(RequestEntity::CHANNEL_MAX));
    }

    #[Test]
    public function aSwitchedOffMaxQueuesNothing(): void
    {
        self::bootKernel();
        $this->maxReady();
        $this->setting('maxOn', false);
        $r = $this->request();
        $bus = new FailingMessageBus();

        $this->channel($bus)->send($this->newRequestNote($r));

        self::assertSame(0, $bus->attempts);
        self::assertFalse($this->max()->isActive());
        self::assertTrue($this->max()->isConnected());
    }

    #[Test]
    public function aConnectedMaxWithADownQueueMarksOnlyMaxFailedAndRingsTheBell(): void
    {
        self::bootKernel();
        $this->maxReady();
        $r = $this->request();
        // The form process still holds the original pending entity when the Telegram worker commits.
        $other = DriverManager::getConnection(self::getContainer()->get(EntityManagerInterface::class)->getConnection()->getParams());
        $other->executeStatement('UPDATE itk_request SET channels = ?, delivery = ? WHERE id = ?', ['{"telegram":{"state":"sent","error":null}}', 'sent', $r->id]);
        $other->close();
        $bus = new FailingMessageBus();

        $this->channel($bus)->send($this->newRequestNote($r));

        self::assertSame(1, $bus->attempts);
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->channelState(RequestEntity::CHANNEL_TELEGRAM), 'Telegram is not touched');
        self::assertSame(RequestEntity::DELIVERY_FAILED, $r->channelState(RequestEntity::CHANNEL_MAX));
        self::assertSame(RequestEntity::DELIVERY_FAILED, $r->delivery);
        self::assertStringStartsWith('MAX: ', (string) $r->deliveryError);
        self::assertStringContainsString('Queue is down', (string) $r->deliveryError);
        self::assertSame(1, $this->bell($r, Notification::MAX_FAILED));
        self::assertSame(0, $this->bell($r, Notification::TELEGRAM_FAILED));
    }

    #[Test]
    public function maxQueuesItsOwnMessageAndOtherNotificationsDoNotGoThere(): void
    {
        self::bootKernel();
        $this->maxReady();
        $r = $this->request();
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $channel = $this->channel($bus);

        self::assertSame('max', $channel->name());
        $note = $this->newRequestNote($r);
        self::assertTrue($channel->supports($note));
        self::assertFalse($channel->supports(new Notification(Notification::MAIL_FAILED, 'x')));
        $channel->send($note);

        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0];
        self::assertInstanceOf(SendRequestNotification::class, $message);
        self::assertSame([$r->id, 'max'], [$message->requestId, $message->channel]);
    }

    #[Test]
    public function anOldQueuedMessageWithoutAChannelStillGoesToTelegram(): void
    {
        $old = \unserialize('O:39:"Itk\\Cms\\Request\\SendRequestNotification":2:{s:9:"requestId";i:5;s:6:"resend";b:1;}');

        self::assertInstanceOf(SendRequestNotification::class, $old);
        self::assertSame([5, true, 'telegram'], [$old->requestId, $old->resend, $old->channel]);
        $new = \unserialize(\serialize(new SendRequestNotification(6, false, 'max')));
        self::assertSame([6, false, 'max'], [$new->requestId, $new->resend, $new->channel]);
        self::assertSame('Telegram', \Itk\Cms\Entity\Request::CHANNELS[$old->channel]);
    }

    /* ---------- the notifier ---------- */

    #[Test]
    public function maxSendsTheRequestToItsChatsAndTelegramStaysPending(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $this->setting('maxRoutes', ['*' => self::CHAT . ', 70001', 'call' => '70002']);
        $r = $this->request();

        self::assertTrue($this->max()->notify($r));

        self::assertSame(RequestEntity::DELIVERY_SENT, $r->channelState(RequestEntity::CHANNEL_MAX));
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->channelState(RequestEntity::CHANNEL_TELEGRAM));
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->delivery);
        self::assertSame(['70002'], \array_column($fake->sent, 'chat'), 'the chat of the form beats «other forms»');
        self::assertStringContainsString('№' . $r->id, $fake->sent[0]['text']);
        self::assertStringContainsString('+7(900)000-00-41', $fake->sent[0]['text']);
    }

    #[Test]
    public function theBundleOptionGivesTheChatsWhenTheSettingsHaveNone(): void
    {
        self::bootKernel(['itk' => ['max' => ['chat_ids' => '-70010, -70011']]]);
        $fake = $this->fake();
        $this->setting('maxOn', true);
        $this->setting('maxRoutes', []);
        $r = $this->request();

        self::assertTrue($this->max()->notify($r));

        self::assertSame(['-70010', '-70011'], \array_column($fake->sent, 'chat'));
        self::assertTrue(self::getContainer()->get(SettingsService::class)->hasMaxFallbackChat());
    }

    #[Test]
    public function theTextIsEscapedForMax(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $r = $this->request();
        $r->phone = 'Ivan <script> & "Son\'s"';
        $r->data = ['phone' => $r->phone];

        $this->max()->notify($r);

        $text = $fake->sent[0]['text'];
        self::assertStringContainsString('Ivan &lt;script&gt; &amp; &quot;Son\'s&quot;', $text);
        self::assertStringNotContainsString('&#39;', $text);
        self::assertStringNotContainsString('<script>', $text);
    }

    #[Test]
    public function withoutAChatOrWhileOffOrNotConnectedNothingFailsAndNothingIsSent(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $this->setting('maxRoutes', []);
        $r = $this->request();
        self::assertTrue($this->max()->notify($r), 'no chat');
        $this->setting('maxRoutes', ['*' => self::CHAT]);
        $this->setting('maxOn', false);
        self::assertTrue($this->max()->notify($r), 'switched off');
        $this->setting('maxOn', true);
        $fake->connected = false;
        self::assertTrue($this->max()->notify($r), 'not connected');

        self::assertSame([], $fake->sent);
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertNull($r->deliveryError);
    }

    #[Test]
    public function aRefusedMessageFailsOnlyMaxAndRetriesAndTheBellFollowsTheOutcome(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $r = $this->request();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r->markChannel(RequestEntity::CHANNEL_TELEGRAM, RequestEntity::DELIVERY_SENT);
        $em->flush();
        $handler = self::getContainer()->get(SendRequestNotificationHandler::class);
        $fake->refuse = 'Bot is not a member of the chat';

        try {
            $handler(new SendRequestNotification((int) $r->id, false, 'max'));
            self::fail('a refused message is retried by Messenger');
        } catch (RequestNotDeliveredException $e) {
            self::assertStringContainsString('MAX', $e->getMessage());
        }

        self::assertSame(RequestEntity::DELIVERY_FAILED, $r->channelState('max'));
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->channelState('telegram'), 'Telegram was delivered and stays so');
        self::assertSame(RequestEntity::DELIVERY_FAILED, $r->delivery);
        self::assertSame(self::CHAT . ': Bot is not a member of the chat', $r->channelError('max'));
        self::assertSame('MAX: ' . self::CHAT . ': Bot is not a member of the chat', $r->deliveryError);
        self::assertSame(1, $this->bell($r, Notification::MAX_FAILED));
        self::assertSame(0, $this->bell($r, Notification::TELEGRAM_FAILED));

        $fake->refuse = null; // the retry
        $handler(new SendRequestNotification((int) $r->id, false, 'max'));

        self::assertSame(RequestEntity::DELIVERY_SENT, $r->channelState('max'));
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->delivery);
        self::assertNull($r->deliveryError);
        self::assertSame(0, $this->bell($r, Notification::MAX_FAILED), 'a delivered request takes its notification back');
    }

    #[Test]
    public function aMaxFailureThatEndsUpPendingTakesItsNotificationBack(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $r = $this->request();
        $fake->refuse = 'no';
        $this->max()->notify($r);
        self::assertSame(1, $this->bell($r, Notification::MAX_FAILED));

        $fake->connected = false; // MAX was disconnected before the resend
        self::assertTrue($this->max()->notify($r, true));

        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertSame(0, $this->bell($r, Notification::MAX_FAILED));
    }

    #[Test]
    public function aLateTerminalFailureCannotReleaseTheSameJobWhileItsRetryIsSending(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $r = $this->request();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $deliverer = self::getContainer()->get(RequestDeliverer::class);
        $handler = self::getContainer()->get(SendRequestNotificationHandler::class);
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'max', false);
        $message = new SendRequestNotification((int) $r->id, false, 'max', $job);
        $fake->refuse = 'first worker refusal';
        try {
            $handler($message);
            self::fail('the first attempt must fail before the duplicate is claimed');
        } catch (RequestNotDeliveredException) {
            self::assertSame('retry', $r->channelJobPhase('max'));
        }
        $lateFailure = new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(new Envelope($message), 'async', new RequestNotDeliveredException('first worker refusal'));
        $phaseDuringSend = null;
        $queuedDuringSend = [];
        $newReservation = null;
        $fake->refuse = null;
        $fake->onSend = static function () use ($deliverer, $em, $r, $lateFailure, &$phaseDuringSend, &$queuedDuringSend, &$newReservation): void {
            // Another envelope has already claimed this job when the first worker's final event arrives.
            (new \Itk\Cms\Request\RequestDeliveryJobListener($deliverer))($lateFailure);
            $phaseDuringSend = $r->channelJobPhase('max');
            $queuedDuringSend = $r->queuedChannels();
            $newReservation = RequestDeliverer::reserve($em, (int) $r->id, 'max', true);
        };
        try {
            $handler($message);
        } finally {
            $fake->onSend = null;
        }

        self::assertSame('processing', $phaseDuringSend, 'the terminal event belongs to the previous attempt');
        self::assertSame(['max'], $queuedDuringSend);
        self::assertNull($newReservation, 'the operator cannot start another send while this one is running');
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->channelState('max'));
        self::assertSame('done', $r->channelJobPhase('max'));
        self::assertCount(1, $fake->sent);
    }

    #[Test]
    public function aRequestGoneBeforeItWasSentIsNoFailure(): void
    {
        self::bootKernel();
        $this->maxReady();

        self::getContainer()->get(SendRequestNotificationHandler::class)(new SendRequestNotification(2147483000, false, 'max'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aDeletedManagedRequestIsNotDeliveredOrReportedAsAFailure(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $em->getConnection()->delete('itk_request', ['id' => $r->id]);
        $deliverer = self::getContainer()->get(RequestDeliverer::class);

        self::assertNull($deliverer->deliver((int) $r->id, 'max', false));
        self::assertSame([], $fake->sent);

        $r = $this->request();
        $fake->refuse = 'no access';
        $fake->onSend = static fn () => $em->getConnection()->delete('itk_request', ['id' => $r->id]);

        self::assertNull($deliverer->deliver((int) $r->id, 'max', false));
        self::assertSame(0, $this->bell($r, Notification::MAX_FAILED));
    }

    #[Test]
    public function aSlowMessengerHoldsNoLockAndTheOtherChannelStateSurvives(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $updated = null;
        $fake->onSend = static function () use ($em, $r, &$updated): void {
            $other = DriverManager::getConnection($em->getConnection()->getParams());
            $other->executeStatement("SET lock_timeout = '1s'");
            $updated = $other->executeStatement(
                'UPDATE itk_request SET channels = :c, delivery = :d WHERE id = :id',
                ['c' => '{"telegram": {"state": "sent", "error": null}}', 'd' => 'sent', 'id' => $r->id],
            );
            $other->close();
        };
        $deliverer = new RequestDeliverer($em, $this->telegram(), $this->max(), self::getContainer()->get(AdminLocale::class));

        self::assertTrue($deliverer->deliver((int) $r->id, 'max', false));

        self::assertSame(1, $updated, 'the row is free while the message is on its way');
        self::assertSame(['sent', 'sent', 'sent'], [$r->channelState('telegram'), $r->channelState('max'), $r->delivery], 'what the other worker wrote is kept');
    }

    #[Test]
    public function aLateFailedRetryDoesNotUndoADeliveryAnotherWorkerRecorded(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $fake->refuse = 'MAX is down';
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $fake->onSend = static function () use ($em, $r): void {
            $other = DriverManager::getConnection($em->getConnection()->getParams());
            $other->executeStatement(
                'UPDATE itk_request SET channels = :c, delivery = :d WHERE id = :id',
                ['c' => '{"max": {"state": "sent", "error": null}}', 'd' => 'sent', 'id' => $r->id],
            );
            $other->close();
        };
        $deliverer = new RequestDeliverer($em, $this->telegram(), $this->max(), self::getContainer()->get(AdminLocale::class));

        self::assertTrue($deliverer->deliver((int) $r->id, 'max', false), 'nothing is left to retry');
        self::assertSame(['sent', 'sent'], [$r->channelState('max'), $r->delivery]);
        self::assertSame(0, $this->bell($r, 'max.failed'));
    }

    #[Test]
    public function aMessengerThatHasTheRequestIsNotSentItAgainByARetryButIsByResend(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $deliverer = new RequestDeliverer($em, $this->telegram(), $this->max(), self::getContainer()->get(AdminLocale::class));

        self::assertTrue($deliverer->deliver((int) $r->id, 'max', false));
        self::assertTrue($deliverer->deliver((int) $r->id, 'max', false));
        self::assertCount(1, $fake->sent, 'a duplicate queue message sends nothing');

        self::assertTrue($deliverer->deliver((int) $r->id, 'max', true));
        self::assertCount(2, $fake->sent);
    }

    #[Test]
    public function aMaxFailureDoesNotTouchTelegramAndTheOtherWayRound(): void
    {
        self::bootKernel();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $deliverer = static fn (TelegramNotifier $t): RequestDeliverer => new RequestDeliverer($em, $t, self::getContainer()->get(MaxNotifier::class), self::getContainer()->get(AdminLocale::class));
        $this->setting('tgRoutes', ['*' => '-1001234567890']);
        $this->setting('tgOn', true);
        $r = $this->request();

        $fake->refuse = 'no access';
        self::assertTrue($deliverer($this->telegram())->deliver((int) $r->id, 'telegram', false));
        self::assertFalse($deliverer($this->telegram())->deliver((int) $r->id, 'max', false));

        self::assertSame(['sent', 'failed', 'failed'], [$r->channelState('telegram'), $r->channelState('max'), $r->delivery]);
        self::assertStringNotContainsString('Telegram', (string) $r->deliveryError);

        $fake->refuse = null;
        self::assertFalse($deliverer($this->telegram(refuse: true))->deliver((int) $r->id, 'telegram', true));
        self::assertTrue($deliverer($this->telegram(refuse: true))->deliver((int) $r->id, 'max', true));

        self::assertSame(['failed', 'sent', 'failed'], [$r->channelState('telegram'), $r->channelState('max'), $r->delivery]);
        self::assertSame('Telegram: -1001234567890: Chat not found', $r->deliveryError);
        self::assertSame(1, $this->bell($r, Notification::TELEGRAM_FAILED));
        self::assertSame(0, $this->bell($r, Notification::MAX_FAILED));
    }

    #[Test]
    public function theOrderStaysTelegramThenMaxAfterAReloadFromTheDatabase(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $r->markChannel('max', 'failed', 'no access');
        $r->markChannel('telegram', 'failed', 'bad chat');
        $em->flush();
        $em->clear();

        $loaded = $em->find(RequestEntity::class, $r->id);
        $shown = self::getContainer()->get(RequestService::class)->present($loaded);

        self::assertSame(['telegram', 'max'], \array_keys($loaded->channelStates()));
        self::assertSame("Telegram: bad chat\nMAX: no access", $loaded->deliveryError);
        self::assertSame(['Telegram: bad chat', 'MAX: no access'], \array_map(static fn (array $l): string => $l[1], $shown['log']));
    }

    #[Test]
    public function theDeliveryEventNamesItsMessenger(): void
    {
        self::assertSame('telegram', (new RequestDelivery(new RequestEntity('call'), true))->channel, 'listeners of the Telegram event keep working');
        self::assertSame('max', (new RequestDelivery(new RequestEntity('call'), true, null, false, 'max'))->channel);
    }

    #[Test]
    public function theTestButtonMessageGoesToOneChat(): void
    {
        self::bootKernel();
        $fake = $this->fake();

        $this->max()->sendTo(self::CHAT, 'проверка');
        self::assertSame([['chat' => self::CHAT, 'text' => 'проверка']], $fake->sent);

        $fake->refuse = 'chat not found';
        try {
            $this->max()->sendTo(self::CHAT, 'проверка');
            self::fail('a refusal must be shown');
        } catch (\Itk\Cms\Content\ContentException $e) {
            self::assertSame('Сообщение не доставлено: chat not found', $e->getMessage());
            self::assertSame(502, $e->getCode());
        }
        $fake->connected = false;
        $this->expectExceptionMessage('MAX не подключён на сайте');
        $this->max()->sendTo(self::CHAT, 'проверка');
    }

    /* ---------- the health line ---------- */

    #[Test]
    public function theHealthLineSaysWhatIsMissing(): void
    {
        self::bootKernel();
        $check = self::getContainer()->get(MaxHealthCheck::class);
        self::assertSame('MAX', $check->name());

        $this->setting('maxRoutes', []);
        $this->fake(false);
        self::assertSame(['off', 'MAX не подключён'], [$check->check()->status, $check->check()->message], 'a calm line even without a chat');

        $this->fake();
        $this->setting('maxOn', false);
        self::assertSame('off', $check->check()->status);
        self::assertStringContainsString('выключена в «Настройки → Уведомления»', $check->check()->message);

        $this->setting('maxOn', true);
        self::assertSame('warn', $check->check()->status);
        self::assertStringContainsString('«Заявка на звонок»', $check->check()->message);

        $this->setting('maxRoutes', ['*' => self::CHAT]);
        self::assertSame(['ok', 'MAX подключён'], [$check->check()->status, $check->check()->message]);
    }

    #[Test]
    public function theHealthLineIsCalmWhenTheBundleOptionSwitchesMaxOff(): void
    {
        self::bootKernel(['itk' => ['max' => ['enabled' => false]]]);
        $this->maxReady();

        $result = self::getContainer()->get(MaxHealthCheck::class)->check();

        self::assertSame('off', $result->status);
        self::assertStringContainsString('itk_cms.max.enabled', $result->message);
        self::assertFalse($this->max()->isActive());
    }

    #[Test]
    public function theHealthScreenListsMaxAndTelegramApart(): void
    {
        $this->adminClient();
        $this->fake();

        $lines = \array_column($this->api('GET', 'health')['checks'], 'status', 'name');

        self::assertArrayHasKey('MAX', $lines);
        self::assertArrayHasKey('Telegram', $lines);
    }

    /* ---------- the admin: the request, resend per messenger ---------- */

    #[Test]
    public function aRequestShowsTheDeliveryOfEachMessengerThatIsOnOrHasAnOutcome(): void
    {
        self::bootKernel();
        $this->maxReady();
        $service = self::getContainer()->get(RequestService::class);
        $r = $this->request();

        $shown = $service->present($r);
        self::assertSame([['channel' => 'max', 'state' => 'pending', 'error' => null]], $shown['channels'], 'Telegram is not connected and has no outcome: not listed');
        self::assertSame('pending', $shown['delivery']);

        $r->markChannel('telegram', 'failed', 'bad chat');
        $r->markChannel('max', 'sent');
        $shown = $service->present($r);

        self::assertSame([['channel' => 'telegram', 'state' => 'failed', 'error' => 'bad chat'], ['channel' => 'max', 'state' => 'sent', 'error' => null]], $shown['channels']);
        self::assertSame(['failed', 'failed', 'Telegram: bad chat'], [$shown['delivery'], $shown['st'], $shown['deliveryError']]);
        self::assertSame([['fail', 'Telegram: bad chat'], ['ok', 'Доставлено в MAX']], \array_map(static fn (array $l): array => [$l[0], $l[1]], $shown['log']));
    }

    #[Test]
    public function aRequestNeverSentToMaxCanBeSentThereOnceMaxCanTakeIt(): void
    {
        $this->adminClient();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(RequestService::class);
        $r = $this->request();
        $r->delivery = RequestEntity::DELIVERY_SENT; // an old request: only Telegram had it, `channels` is empty
        $em->flush();

        self::assertSame(['max'], $service->present($r)['sendable']);

        $this->setting('maxOn', false);
        self::assertSame([], $service->present($r)['sendable'], 'MAX is off');

        $this->setting('maxOn', true);
        $this->setting('maxRoutes', ['other' => self::CHAT]);
        self::assertSame([], $service->present($r)['sendable'], 'no chat for this form');

        $this->setting('maxRoutes', ['*' => self::CHAT]);
        $r->markChannel('max', 'failed', 'no access');
        self::assertSame([], $service->present($r)['sendable'], 'a failed MAX has «send again» instead');
        $r->markChannel('max', 'pending');
        $em->flush();

        $item = $this->api('POST', 'requests/' . $r->id . '/resend', ['channel' => 'max']);

        self::assertCount(1, $fake->sent);
        self::assertStringNotContainsString('доставляется повторно', $fake->sent[0]['text'], 'a first send does not say an earlier delivery failed');
        self::assertSame('sent', $item['delivery']);
        self::assertSame([], $item['sendable']);
        self::assertContains(['channel' => 'max', 'state' => 'sent', 'error' => null], $item['channels']);

        $this->api('POST', 'requests/' . $r->id . '/resend', ['channel' => 'max']); // a stale drawer still shows «Отправить в MAX»

        self::assertCount(1, $fake->sent, 'a messenger that already has the request is not sent it twice');
    }

    #[Test]
    public function resendGoesOnlyToTheMessengerThatFailedOrTheOneAsked(): void
    {
        $this->adminClient();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $r->markChannel('telegram', 'failed', 'bad chat');
        $r->markChannel('max', 'failed', self::CHAT . ': no access');
        $em->flush();

        $item = $this->api('POST', 'requests/' . $r->id . '/resend', ['channel' => 'max']);

        self::assertCount(1, $fake->sent, 'only MAX was asked');
        self::assertSame([['channel' => 'telegram', 'state' => 'failed', 'error' => 'bad chat'], ['channel' => 'max', 'state' => 'sent', 'error' => null]], $item['channels']);
        self::assertSame('failed', $item['delivery'], 'Telegram still failed');

        $item = $this->api('POST', 'requests/' . $r->id . '/resend'); // no body: each messenger that failed, here Telegram, which this site does not have connected

        self::assertCount(1, $fake->sent, 'MAX had not failed: it is not sent again');
        self::assertSame('sent', $item['delivery'], 'Telegram settled to «pending» without a connection, MAX stays delivered');
        self::assertSame([['channel' => 'max', 'state' => 'sent', 'error' => null]], $item['channels']);
    }

    #[Test]
    public function resendWithNoFailureGoesToEveryMessengerThatIsOn(): void
    {
        $this->adminClient();
        $fake = $this->maxReady();
        $r = $this->request();

        $this->api('POST', 'requests/' . $r->id . '/resend');

        self::assertCount(1, $fake->sent);
        self::assertStringContainsString('доставляется повторно', $fake->sent[0]['text']);
    }

    #[Test]
    public function theHealthScreenResendsPerMessengerToo(): void
    {
        $this->adminClient();
        $fake = $this->maxReady();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $this->request();
        $r->markChannel('telegram', 'failed', 'bad chat');
        $r->markChannel('max', 'failed', 'no access');
        $em->flush();

        $answer = $this->api('POST', 'health/requests/' . $r->id . '/resend', ['channel' => 'max']);

        self::assertTrue($answer['ok']);
        self::assertSame('failed', $answer['delivery']);
        self::assertSame('Telegram: bad chat', $answer['error'], 'MAX got through, only Telegram is left in the error');
        self::assertSame([['channel' => 'telegram', 'state' => 'failed', 'error' => 'bad chat'], ['channel' => 'max', 'state' => 'sent', 'error' => null]], $answer['channels']);
        self::assertCount(1, $fake->sent);

        foreach (['signal', ['max'], false, null] as $invalid) {
            $this->api('POST', 'health/requests/' . $r->id . '/resend', ['channel' => $invalid], 422);
            $this->api('POST', 'requests/' . $r->id . '/resend', ['channel' => $invalid], 422);
        }
        self::assertCount(1, $fake->sent);
    }

    #[Test]
    public function requeueingQueuesEachFailedMessengerAlone(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $onlyMax = $this->request();
        $onlyMax->markChannel('max', 'failed', 'x');
        $both = $this->request();
        $both->markChannel('telegram', 'failed', 'y');
        $both->markChannel('max', 'failed', 'x');
        $legacy = $this->request();
        $legacy->delivery = RequestEntity::DELIVERY_FAILED;
        $em->flush();
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $transport->reset();

        $count = self::getContainer()->get(RequestService::class)->requeueFailed();

        $queued = [];
        foreach ($transport->getSent() as $envelope) {
            $m = $envelope->getMessage();
            \assert($m instanceof SendRequestNotification);
            $queued[] = $m->requestId . ':' . $m->channel . ':' . ($m->resend ? 'resend' : 'first');
        }
        \sort($queued);
        $expected = [$onlyMax->id . ':max:resend', $both->id . ':telegram:resend', $both->id . ':max:resend', $legacy->id . ':telegram:resend'];
        \sort($expected);
        self::assertSame($expected, $queued);
        self::assertGreaterThanOrEqual(3, $count);
    }

    /* ---------- the settings ---------- */

    #[Test]
    public function theMaxSettingsAreSavedCheckedAndSeenOnlyByThoseWhoMaySeeNotifications(): void
    {
        $this->adminClient();
        $settings = self::getContainer()->get(SettingsService::class);
        foreach (['maxOn', 'maxRoutes'] as $key) {
            $this->saved[$key] = [$settings->isSet($key), $settings->get($key)];
            $settings->remove($key);
        }
        $all = $this->api('GET', 'settings');
        self::assertTrue($all['maxOn']);
        self::assertSame([], $all['maxRoutes']);
        self::assertFalse($all['maxReady'], 'no MAX bot on this site');
        self::assertFalse($all['tgReady'], 'no Telegram bot on this site');
        self::assertFalse($all['maxFallback']);

        $saved = $this->api('PATCH', 'settings', ['maxRoutes' => ['*' => ' -70001 , 70002', 'call' => '70003'], 'maxOn' => false]);
        self::assertSame(['*' => '-70001 , 70002', 'call' => '70003'], $saved['maxRoutes']);
        self::assertFalse($saved['maxOn']);
        self::assertSame(['-70001', '70002'], self::getContainer()->get(SettingsService::class)->maxChatIdsFor('page'));

        foreach (['@channel', 'abc', '12x', '1.5', '123456789012345678901'] as $bad) {
            $error = $this->api('PATCH', 'settings', ['maxRoutes' => ['*' => '70001, ' . $bad]], 422);
            self::assertSame('Проверьте ID чатов MAX', $error['error'] ?? $error['message'] ?? null, $bad);
        }
        self::assertSame(['*' => '-70001 , 70002', 'call' => '70003'], $this->api('GET', 'settings')['maxRoutes'], 'a rejected patch changes nothing');

        $this->api('PATCH', 'settings', ['maxRoutes' => ['*' => '']]);
        $this->api('PATCH', 'settings', ['maxOn' => true], 200); // MAX is not connected here: no chat is asked for
        $this->fake();
        $this->api('PATCH', 'settings', ['maxRoutes' => ['*' => self::CHAT], 'maxOn' => true]);
        $cleared = $this->api('PATCH', 'settings', ['maxRoutes' => ['*' => '']], 200); // a connected MAX lets the owner clear the chat too
        self::assertSame([], \array_filter($cleared['maxRoutes']));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-max-%@example.com')->execute();
        $editor = self::getContainer()->get(UserManager::class)->create('test-max-editor@example.com', 'Тест MAX', 'editor', 'password-for-test');
        \assert($editor instanceof User);
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->loginUser($editor, 'itk_admin');
        $seen = $this->api('GET', 'settings');
        foreach (['maxOn', 'maxRoutes', 'maxFallback', 'maxReady', 'tgReady', 'tgOn', 'tgRoutes'] as $key) {
            self::assertArrayNotHasKey($key, $seen, 'an editor does not see ' . $key);
        }
    }

    #[Test]
    public function aConnectedMaxSavesWithoutAChatForTheOtherForms(): void
    {
        $this->adminClient();
        $this->fake();
        $this->saved['maxRoutes'] = [false, null];
        $this->saved['maxOn'] = [false, null];

        self::assertTrue($this->api('GET', 'settings')['maxReady']);
        $this->api('PATCH', 'settings', ['maxOn' => true, 'maxRoutes' => []]); // the health screen warns about forms without a chat; saving is not refused
        self::assertSame([], self::getContainer()->get(SettingsService::class)->maxChatIdsFor('call'));
        $this->api('PATCH', 'settings', ['maxOn' => false, 'maxRoutes' => []]);
        $this->api('PATCH', 'settings', ['maxOn' => true, 'maxRoutes' => ['*' => self::CHAT, 'call' => '']]);
        self::assertSame([self::CHAT], self::getContainer()->get(SettingsService::class)->maxChatIdsFor('call'));
    }

    #[Test]
    public function aServerChatCountsAsAChatAndIsOnlyAYesToTheAdmin(): void
    {
        $this->adminClient(['itk' => ['max' => ['chat_ids' => '-70010']]]);
        $this->fake();
        $this->saved['maxRoutes'] = [false, null];
        $this->saved['maxOn'] = [false, null];

        $all = $this->api('GET', 'settings');
        self::assertTrue($all['maxFallback']);
        self::assertStringNotContainsString('70010', (string) \json_encode($all), 'the ids stay on the server');
        $this->api('PATCH', 'settings', ['maxOn' => true, 'maxRoutes' => []]);
    }

    #[Test]
    public function theTestButtonSendsToTheChatsOfTheFormAndExplainsFailures(): void
    {
        $this->adminClient();
        $fake = $this->fake(false);
        $this->setting('maxRoutes', ['*' => self::CHAT . ', 70001']);

        $error = $this->api('POST', 'settings/max-test', ['chatId' => self::CHAT], 502);
        self::assertStringContainsString('MAX не подключён на сайте', (string) ($error['error'] ?? $error['message'] ?? ''));

        $fake->connected = true;
        $answer = $this->api('POST', 'settings/max-test', []);
        self::assertSame(['ok' => true, 'chat' => 'Остальные формы', 'sent' => 2], $answer);
        self::assertSame([self::CHAT, '70001'], \array_column($fake->sent, 'chat'));
        self::assertStringContainsString('Проверка связи', $fake->sent[0]['text']);

        self::assertSame(1, $this->api('POST', 'settings/max-test', ['chatId' => '70009'])['sent']);

        $this->api('POST', 'settings/max-test', ['chatId' => '@channel'], 422);
        $this->setting('maxRoutes', []);
        $this->api('POST', 'settings/max-test', [], 422);

        $fake->refuse = 'chat not found';
        $error = $this->api('POST', 'settings/max-test', ['chatId' => self::CHAT], 502);
        self::assertSame('Сообщение не доставлено: chat not found', $error['error'] ?? $error['message'] ?? null);
    }

    #[Test]
    public function theTestButtonSaysMaxIsNotConnectedBeforeLookingForChats(): void
    {
        $this->adminClient();
        $fake = $this->fake(false);
        $this->setting('maxRoutes', []);

        $error = $this->api('POST', 'settings/max-test', [], 502);
        self::assertStringContainsString('MAX не подключён на сайте', (string) ($error['error'] ?? $error['message'] ?? ''));
        self::assertSame([], $fake->sent);
    }

    #[Test]
    public function theTestButtonSplitsAndChecksSeveralChatIds(): void
    {
        $this->adminClient();
        $fake = $this->fake();

        $answer = $this->api('POST', 'settings/max-test', ['chatId' => '-1, -2']);
        self::assertSame(2, $answer['sent']);
        self::assertSame(['-1', '-2'], \array_column($fake->sent, 'chat'));

        $fake->sent = [];
        $this->api('POST', 'settings/max-test', ['chatId' => '-1,@bad'], 422);
        self::assertSame([], $fake->sent, 'nothing goes out before every id is checked');
    }

    #[Test]
    public function anEditorCannotUseTheTestButton(): void
    {
        $this->adminClient();
        $editor = self::getContainer()->get(UserManager::class)->create('test-max-editor@example.com', 'Тест MAX', 'editor', 'password-for-test');
        \assert($editor instanceof User);
        $client = static::getClient();
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->loginUser($editor, 'itk_admin');
        $this->fake();

        $this->api('POST', 'settings/max-test', ['chatId' => self::CHAT], 403);
        self::assertSame([], self::getContainer()->get(FakeMaxSender::class)->sent);
    }
}

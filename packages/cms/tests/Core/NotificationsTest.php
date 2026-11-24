<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request as RequestEntity;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Entity\User;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Notification\Audience;
use Itk\Cms\Notification\Channel\DatabaseChannel;
use Itk\Cms\Notification\Channel\TelegramChannel;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationInbox;
use Itk\Cms\Notification\Notifier;
use Itk\Cms\Notification\NotifierInterface;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Security\Viewer;
use Itk\Cms\Tests\Core\Fixture\TestChannel;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/** Уведомления: дверь для сообщений, каналы, колокольчик (аудитория, «прочитано»), заявка → уведомления, сбой Telegram. */
final class NotificationsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificationInbox $inbox;
    /** @var list<int> */
    private array $requestIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->inbox = new NotificationInbox($this->em);
        // the tests count what a person sees: a note left by any other test (or a letter of the site that failed) must not be in the box
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n')->execute();
        TestChannel::$log = [];
        TestChannel::$fail = false;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.type LIKE :t')->setParameter('t', 'test.%')->execute();
        foreach ($this->requestIds as $id) {
            $this->em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.dedupeKey = :k OR n.title = :t')
                ->setParameter('k', Notification::TELEGRAM_FAILED . ':' . $id)->setParameter('t', 'Новая заявка №' . $id)->execute();
            $this->em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id = :i')->setParameter('i', $id)->execute();
        }
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        return self::getContainer()->get(UserManager::class)->create('test-' . $name . '@example.com', 'Test ' . $name, $role, 'password-for-test');
    }

    private function viewer(User $u): Viewer
    {
        return Viewer::of($u, self::getContainer()->get(RoleResolver::class)->rightsOf($u));
    }

    private function send(Notification $n): void
    {
        (new DatabaseChannel($this->em))->send($n);
    }

    private function note(string $title, ?string $audience = null, ?string $key = null): Notification
    {
        return new Notification('test.test', $title, audience: $audience, key: $key);
    }

    /** @return list<string> */
    private function titles(User $u, bool $unread = false): array
    {
        return \array_column($this->inbox->list($u, $this->viewer($u), $unread)['items'], 'title');
    }

    #[Test]
    public function aNotificationChecksItsParts(): void
    {
        foreach ([
            ['level' => 'fatal'], ['title' => '  '], ['type' => ''], ['link' => 'javascript:alert(1)'], ['link' => '//evil.example/x'], ['key' => ''],
        ] as $bad) {
            try {
                new Notification(...($bad + ['type' => 'test.test', 'title' => 'Заголовок']));
                self::fail('A bad notification was accepted: ' . \json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $ok = new Notification('test.test', 'Заголовок', link: '/requests/5', level: Notification::WARNING);
        self::assertSame('/requests/5', $ok->link);
        self::assertSame('https://example.com/a', (new Notification('test.test', 'Т', link: 'https://example.com/a'))->link);
    }

    #[Test]
    public function theNotifierGivesAMessageToTheChannelsThatSupportItAndSurvivesABrokenOne(): void
    {
        $registry = new Registry([]);
        $registry->notificationChannels(TestChannel::class);
        $notifier = new Notifier([new DatabaseChannel($this->em)], $registry, new NullLogger());

        $notifier->notify(new Notification('test.chan', 'Первое'));
        $notifier->notify(new Notification('test.other', 'Чужое'));
        self::assertSame(['Первое'], TestChannel::$log, 'a class named in notificationChannels() is created and asked');
        self::assertSame(['database', 'test'], \array_map(static fn ($c): string => $c->name(), $notifier->channels()));

        TestChannel::$fail = true;
        $notifier->notify(new Notification('test.chan', 'Второе')); // does not throw, the other channel still stores it
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(StoredNotification::class)->count(['title' => 'Второе']));

        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore argument.type (Verify the runtime guard for extensions without static analysis.)
        (new Registry([]))->notificationChannels(\stdClass::class);
    }

    #[Test]
    public function theDatabaseChannelKeepsOneRowPerKey(): void
    {
        $this->send($this->note('Раз', null, 'test-key:1'));
        $this->send($this->note('Два', null, 'test-key:1'));
        $this->send($this->note('Три'));
        $this->send($this->note('Четыре'));
        $this->em->clear();

        self::assertSame(1, $this->em->getRepository(StoredNotification::class)->count(['dedupeKey' => 'test-key:1']));
        self::assertSame(2, $this->em->getRepository(StoredNotification::class)->count(['title' => ['Три', 'Четыре']]));
        self::assertSame(1, $this->inbox->resolve('test-key:1'));
    }

    #[Test]
    public function anAudienceIsARightOrARole(): void
    {
        $admin = $this->viewer($this->user('aud-admin', 'admin'));
        $requests = $this->viewer($this->user('aud-requests', 'requests'));
        $editor = $this->viewer($this->user('aud-editor', 'editor'));

        self::assertTrue(Audience::matches(null, $editor));
        self::assertFalse(Audience::matches(null, Viewer::system()), 'the bell is for people');
        self::assertTrue(Audience::matches('requests', $requests));
        self::assertTrue(Audience::matches('requests:edit', $requests));
        self::assertFalse(Audience::matches('requests:delete', $requests));
        self::assertFalse(Audience::matches('set:users', $requests));
        self::assertTrue(Audience::matches('set:users', $admin));
        self::assertTrue(Audience::matches('role:editor', $editor));
        self::assertFalse(Audience::matches('role:editor', $admin));
        self::assertFalse(Audience::matches('role:', $admin));
    }

    #[Test]
    public function aPersonSeesWhatIsForHimCountsUnreadAndMarksRead(): void
    {
        $admin = $this->user('box-admin', 'admin');
        $requests = $this->user('box-requests', 'requests');
        $this->send($this->note('Всем'));
        $this->send($this->note('Заявки', 'requests'));
        $this->send($this->note('Только админам', 'set:users'));
        $this->send($this->note('Редакторам', 'role:editor'));

        self::assertEqualsCanonicalizing(['Всем', 'Заявки', 'Только админам'], $this->titles($admin));
        self::assertEqualsCanonicalizing(['Всем', 'Заявки'], $this->titles($requests));
        self::assertSame(3, $this->inbox->unread($admin, $this->viewer($admin)));
        $unread = $this->inbox->unread($requests, $this->viewer($requests));
        self::assertSame(2, $unread);

        $first = $this->inbox->list($requests, $this->viewer($requests), true, 1);
        self::assertCount(1, $first['items']);
        self::assertSame($unread, $first['total']);
        self::assertSame($unread, $first['unread']);
        self::assertFalse($first['items'][0]['read']);

        $left = $this->inbox->markRead($requests, $this->viewer($requests), [$first['items'][0]['id']]);
        self::assertSame($unread - 1, $left);
        self::assertSame($unread - 1, $this->inbox->unread($requests, $this->viewer($requests)));

        // what he may not see cannot be marked
        $hidden = $this->em->getRepository(StoredNotification::class)->findOneBy(['title' => 'Только админам']);
        $this->inbox->markRead($requests, $this->viewer($requests), [$hidden->id]);
        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_notification_read WHERE notification_id = ? AND user_id = ?', [$hidden->id, $requests->id]));

        self::assertSame(0, $this->inbox->markAllRead($requests, $this->viewer($requests)));
        self::assertSame([], $this->titles($requests, true));
        self::assertSame(['Заявки', 'Всем'], $this->titles($requests), 'read ones stay in the list, the newest first');
        self::assertTrue($this->inbox->list($requests, $this->viewer($requests))['items'][0]['read']);
        self::assertSame(3, $this->inbox->unread($admin, $this->viewer($admin)), 'his marks are his own');
    }

    #[Test]
    public function aPersonDoesNotSeeWhatWasSentBeforeHeJoined(): void
    {
        $this->send($this->note('Старое'));
        $this->em->createQuery('UPDATE Itk\Cms\Entity\StoredNotification n SET n.createdAt = :d WHERE n.title = :t')
            ->setParameter('d', new \DateTimeImmutable('-2 days'))->setParameter('t', 'Старое')->execute();
        $newcomer = $this->user('box-new', 'editor');
        $this->send($this->note('Новое'));

        self::assertSame(['Новое'], $this->titles($newcomer));
    }

    #[Test]
    public function oldNotificationsArePruned(): void
    {
        $this->send($this->note('Давнее'));
        $this->send($this->note('Свежее'));
        $this->em->createQuery('UPDATE Itk\Cms\Entity\StoredNotification n SET n.createdAt = :d WHERE n.title = :t')
            ->setParameter('d', new \DateTimeImmutable('-100 days'))->setParameter('t', 'Давнее')->execute();

        self::assertGreaterThanOrEqual(1, $this->inbox->prune(90, true));
        $this->inbox->prune(90);
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(StoredNotification::class)->count(['title' => 'Давнее']));
        self::assertSame(1, $this->em->getRepository(StoredNotification::class)->count(['title' => 'Свежее']));
    }

    #[Test]
    public function aNewRequestRingsTheBellForThoseWhoSeeRequests(): void
    {
        $requests = $this->user('req-requests', 'requests');
        $editorOnlyContent = $this->user('req-author', 'author');

        $r = self::getContainer()->get(RequestService::class)->submit('call', ['phone' => '+7(900)000-00-11', 'city' => 'Тест']);
        $this->requestIds[] = (int) $r->id;

        $mine = $this->inbox->list($requests, $this->viewer($requests))['items'];
        $row = \array_values(\array_filter($mine, static fn (array $i): bool => 'Новая заявка №' . $r->id === $i['title']))[0] ?? null;
        self::assertNotNull($row, 'the bell has the request');
        self::assertSame(['request.new', '/requests/' . $r->id, 'info'], [$row['type'], $row['link'], $row['level']]);
        self::assertStringContainsString('Заявка на звонок', $row['body']);
        self::assertSame($r->id, $row['payload']->requestId);
        self::assertNotContains('Новая заявка №' . $r->id, $this->titles($editorOnlyContent), 'a person without the right to requests is not told');
    }

    #[Test]
    public function theTelegramChannelQueuesTheDeliveryAndMarksTheRequestWhenTheQueueIsDown(): void
    {
        $admin = $this->user('tg-admin', 'admin');
        $r = $this->request();
        $queued = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };
        $down = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('очередь недоступна');
            }
        };
        $events = self::getContainer()->get('event_dispatcher');
        $note = new Notification(Notification::REQUEST_NEW, 'Новая заявка №' . $r->id, payload: ['requestId' => $r->id]);
        // the channel queues only for a connected Telegram (a site without it: TelegramChannelTest)
        $telegram = new TelegramNotifier(self::getContainer()->get(Schema::class), self::getContainer()->get(SettingsService::class), new NullLogger(), static fn (): TransportInterface => new TelegramTransport('123456:test-token'), $events, true);

        $ok = new TelegramChannel($this->em, $queued, $events, new NullLogger(), $telegram);
        self::assertTrue($ok->supports($note));
        self::assertFalse($ok->supports(new Notification(Notification::TELEGRAM_FAILED, 'Сбой', payload: ['requestId' => $r->id])), 'only new requests go to Telegram');
        $ok->send($note);
        self::assertCount(1, $queued->sent);
        self::assertInstanceOf(SendRequestNotification::class, $queued->sent[0]);
        self::assertSame($r->id, $queued->sent[0]->requestId);
        self::assertSame('telegram', $queued->sent[0]->channel);
        self::assertNotNull($queued->sent[0]->jobId);
        $this->em->refresh($r);
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertSame(['telegram'], $r->queuedChannels());
        $ok->send($note);
        self::assertCount(1, $queued->sent, 'an active job cannot be queued twice');

        $rejected = $this->request();
        $rejectedNote = new Notification(Notification::REQUEST_NEW, 'Новая заявка №' . $rejected->id, payload: ['requestId' => $rejected->id]);
        (new TelegramChannel($this->em, $down, $events, new NullLogger(), $telegram))->send($rejectedNote);
        $this->em->refresh($rejected);
        self::assertSame(RequestEntity::DELIVERY_FAILED, $rejected->delivery);
        self::assertSame([], $rejected->queuedChannels());
        self::assertStringContainsString('очередь недоступна', (string) $rejected->deliveryError);
        self::assertContains('Заявка №' . $rejected->id . ' не дошла в Telegram', $this->titles($admin), 'the failure reaches the bell');
    }

    #[Test]
    public function aFailedDeliveryIsOneNotificationUntilItSucceeds(): void
    {
        $r = $this->request();
        $admin = $this->user('tg-fail', 'admin');
        $author = $this->user('tg-author', 'author');
        $events = self::getContainer()->get('event_dispatcher');
        // Telegram is connected and has a chat, but the message is refused: that is the failure (a site without Telegram has none, see TelegramOptionalTest)
        $settings = self::getContainer()->get(SettingsService::class);
        $routesWereSet = $settings->isSet('tgRoutes');
        $routesBefore = $settings->get('tgRoutes');
        $tgOnWasSet = $settings->isSet('tgOn');
        $tgOnBefore = $settings->get('tgOn');
        $settings->set('tgRoutes', ['*' => '-1001234567890']);
        $settings->set('tgOn', true);
        $refusing = new class implements TransportInterface {
            public function send(MessageInterface $message): SentMessage
            {
                throw new \RuntimeException('chat not found');
            }

            public function supports(MessageInterface $message): bool
            {
                return $message instanceof ChatMessage;
            }

            public function __toString(): string
            {
                return 'refusing://default';
            }
        };
        $telegram = new TelegramNotifier(self::getContainer()->get(Schema::class), $settings, new NullLogger(), static fn (): TransportInterface => $refusing, $events, true);
        $title = 'Заявка №' . $r->id . ' не дошла в Telegram';

        try {
            self::assertFalse($telegram->notify($r));
            self::assertFalse($telegram->notify($r, true));
            $this->em->flush();

            self::assertSame(RequestEntity::DELIVERY_FAILED, $r->delivery);
            self::assertSame(1, \count(\array_keys($this->titles($admin), $title, true)), 'a repeated failure does not pile up');
            self::assertNotContains($title, $this->titles($author), 'only those who can resend it are told');
            $row = $this->em->getRepository(StoredNotification::class)->findOneBy(['dedupeKey' => 'telegram.failed:' . $r->id]);
            self::assertSame(['error', '/requests/' . $r->id, 'requests:edit'], [$row->level, $row->link, $row->audience]);

            // it was delivered after all (a resend): the notification goes away
            $events->dispatch(new RequestDelivery($r, true));
            $this->em->clear();
            self::assertSame(0, $this->em->getRepository(StoredNotification::class)->count(['dedupeKey' => 'telegram.failed:' . $r->id]));
        } finally {
            foreach (['tgRoutes' => [$routesWereSet, $routesBefore], 'tgOn' => [$tgOnWasSet, $tgOnBefore]] as $key => [$was, $value]) {
                $this->em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', $key)->execute();
                if ($was) {
                    $settings->set($key, $value);
                }
            }
        }
    }

    #[Test]
    public function theNotifierIsTheOneDoorOfTheContainer(): void
    {
        self::assertInstanceOf(Notifier::class, self::getContainer()->get(NotifierInterface::class));
        $names = \array_map(static fn ($c): string => $c->name(), self::getContainer()->get(NotifierInterface::class)->channels());
        self::assertContains('database', $names);
        self::assertContains('telegram', $names);
        self::assertContains('max', $names);
    }

    private function request(): RequestEntity
    {
        $r = new RequestEntity('call');
        $r->phone = '+7(900)000-00-12';
        $r->data = ['phone' => '+7(900)000-00-12'];
        $this->em->persist($r);
        $this->em->flush();
        $this->requestIds[] = (int) $r->id;

        return $r;
    }
}

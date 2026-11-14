<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request as RequestEntity;
use Itk\Cms\Entity\StoredNotification;
use Itk\Cms\Entity\User;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\Health\TelegramHealthCheck;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Request\SendRequestNotificationHandler;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\NullTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Component\Notifier\Transport\Transports;

/**
 * TELEGRAM_DSN is optional: a site without it (no chatter transport, or one that reads an environment variable nobody set) takes requests, the admin works,
 * a request is saved and gets the neutral delivery «pending» — not a failure, no retries. A configured Telegram still sends.
 */
final class TelegramOptionalTest extends WebTestCase
{
    private const CHAT = '-1001234567890';
    private const NOTIFIER_ENV = ['notifier' => ['chatter_transports' => ['telegram' => '%env(TELEGRAM_DSN)%']]];
    private const NOTIFIER_NULL = ['notifier' => ['chatter_transports' => ['telegram' => 'null://null']]];
    private const NOTIFIER_TELEGRAM = ['notifier' => ['chatter_transports' => ['telegram' => 'telegram://123456:test-token@default']]];

    /** @var list<int> */
    private array $requestIds = [];
    /** @var array<string, array{bool, mixed}> setting => [was it saved, its value]: put back after the test */
    private array $saved = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, null, '', $options['framework'] ?? []);
    }

    protected function setUp(): void
    {
        unset($_SERVER['TELEGRAM_DSN'], $_ENV['TELEGRAM_DSN']);
        \putenv('TELEGRAM_DSN');
    }

    protected function tearDown(): void
    {
        if (null !== static::$kernel) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            foreach ($this->requestIds as $id) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.dedupeKey = :k OR n.title = :t')->setParameter('k', 'telegram.failed:' . $id)->setParameter('t', 'Новая заявка №' . $id)->execute();
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id = :i')->setParameter('i', $id)->execute();
            }
            $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-tg-%@example.com')->execute();
            $settings = self::getContainer()->get(SettingsService::class);
            foreach ($this->saved as $key => [$existed, $value]) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', $key)->execute();
                if ($existed) {
                    $settings->set($key, $value);
                }
            }
        }
        unset($_SERVER['TELEGRAM_DSN'], $_ENV['TELEGRAM_DSN']);
        \putenv('TELEGRAM_DSN');
        parent::tearDown();
    }

    private function setting(string $key, mixed $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->saved[$key] ??= [$settings->isSet($key), $settings->get($key)];
        $settings->set($key, $value);
    }

    /** The site has a chat for the requests (Settings → Уведомления), Telegram is on. */
    private function chatSet(): void
    {
        $this->setting('tgRoutes', ['*' => self::CHAT]);
        $this->setting('tgOn', true);
    }

    private function request(): RequestEntity
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = new RequestEntity('call');
        $r->phone = '+7(900)000-00-21';
        $r->data = ['phone' => '+7(900)000-00-21'];
        $em->persist($r);
        $em->flush();
        $this->requestIds[] = (int) $r->id;

        return $r;
    }

    private function notifier(?\Closure $chatter): TelegramNotifier
    {
        return new TelegramNotifier(
            self::getContainer()->get(Schema::class),
            self::getContainer()->get(SettingsService::class),
            new NullLogger(),
            $chatter,
            self::getContainer()->get('event_dispatcher'),
            true,
        );
    }

    /** A transport that remembers what it was asked to send (and may refuse). */
    private function recorder(bool $refuse = false): TransportInterface
    {
        return new class($refuse) implements TransportInterface {
            /** @var list<ChatMessage> */
            public array $sent = [];

            public function __construct(private readonly bool $refuse)
            {
            }

            public function send(MessageInterface $message): SentMessage
            {
                if ($this->refuse) {
                    throw new \RuntimeException('Chat not found');
                }
                \assert($message instanceof ChatMessage);
                $this->sent[] = $message;

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
    }

    #[Test]
    public function aSiteWithoutChatterTransportsTakesTheRequestWithoutAFailure(): void
    {
        self::bootKernel();
        $this->chatSet();
        $r = $this->request();
        $telegram = $this->notifier(static fn (): ?object => null);

        self::assertFalse($telegram->isConnected());
        self::assertTrue($telegram->notify($r), 'nothing to retry');
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertNull($r->deliveryError);
    }

    #[Test]
    public function theContainerWiresNoTransportWhenTheSiteConfiguredNone(): void
    {
        self::bootKernel(); // the test site has no framework.notifier.chatter_transports
        $this->chatSet();
        $r = $this->request();
        $telegram = self::getContainer()->get(TelegramNotifier::class);

        self::assertFalse($telegram->isConnected());
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
    }

    #[Test]
    public function aTransportThatReadsAMissingEnvironmentVariableIsNoFailureEither(): void
    {
        self::bootKernel(['framework' => self::NOTIFIER_ENV]); // TELEGRAM_DSN is not set anywhere
        $this->chatSet();
        $r = $this->request();
        $telegram = self::getContainer()->get(TelegramNotifier::class);

        self::assertFalse($telegram->isConnected());
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertNull($r->deliveryError);

        (self::getContainer()->get(SendRequestNotificationHandler::class))(new SendRequestNotification((int) $r->id)); // the worker neither throws (no retry) nor fails the request
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
    }

    #[Test]
    public function aMissingEnvironmentVariableDoesNotBreakTheAdminApiOrTheForms(): void
    {
        $client = static::createClient(['framework' => self::NOTIFIER_ENV]);
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = (int) $em->getRepository(RequestEntity::class)->count([]);

        $client->request('POST', '/request/call', ['phone' => '+7(900)000-00-22']);
        self::assertResponseIsSuccessful();
        self::assertTrue(\json_decode((string) $client->getResponse()->getContent(), true)['success']);
        $created = $em->getRepository(RequestEntity::class)->findOneBy([], ['id' => 'DESC']);
        self::assertSame($before + 1, (int) $em->getRepository(RequestEntity::class)->count([]));
        $this->requestIds[] = (int) $created->id;

        $admin = self::getContainer()->get(UserManager::class)->create('test-tg-env@example.com', 'Тест Telegram', 'admin', 'password-for-test');
        \assert($admin instanceof User);
        $client->loginUser($admin, 'itk_admin');
        foreach (['requests', 'settings', 'health'] as $path) {
            $client->request('GET', '/admin/api/' . $path);
            self::assertResponseIsSuccessful($path);
        }
        $health = (array) \json_decode((string) $client->getResponse()->getContent(), true);
        $lines = \array_column($health['checks'], 'message', 'name');
        self::assertSame('Telegram не подключён', $lines['Telegram'] ?? null);
        self::assertSame('ok', \array_column($health['checks'], 'status', 'name')['Telegram'] ?? null, 'a calm line, not a warning');
    }

    #[Test]
    public function aDisconnectedTelegramRequiresValidIdsButNeedsNoFallback(): void
    {
        self::bootKernel(['framework' => self::NOTIFIER_NULL]);
        $this->setting('tgRoutes', []);
        $this->setting('tgOn', true);
        $settings = self::getContainer()->get(SettingsService::class);
        self::assertSame([], $settings->update(['tgOn' => true, 'tgRoutes' => []])['tgRoutes']);
        $this->expectException(ContentException::class);
        $settings->update(['tgRoutes' => ['*' => 'bad chat']]);
    }

    #[Test]
    public function aConnectedTelegramStillRequiresAFallbackRoute(): void
    {
        self::bootKernel(['framework' => self::NOTIFIER_TELEGRAM]);
        $this->setting('tgRoutes', []);
        $this->setting('tgOn', true);
        $this->expectException(ContentException::class);
        self::getContainer()->get(SettingsService::class)->update(['tgOn' => true, 'tgRoutes' => []]);
    }

    #[Test]
    public function aPresentedRequestOfASiteWithoutTelegramSaysSo(): void
    {
        self::bootKernel();
        $this->chatSet();
        $r = $this->request();
        $this->notifier(static fn (): ?object => null)->notify($r);

        $shown = self::getContainer()->get(RequestService::class)->present($r);

        self::assertSame('pending', $shown['delivery']);
        self::assertSame('delivered', $shown['st'], 'the list marks only failures');
        self::assertSame([['ok', 'Мессенджеры не подключены. Заявка сохранена здесь', $r->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z')]], $shown['log']);
    }

    #[Test]
    public function withTelegramOnAndNoChatNothingFailsEither(): void
    {
        self::bootKernel();
        $this->setting('tgRoutes', []);
        $this->setting('tgOn', true);
        $settings = self::getContainer()->get(SettingsService::class);
        $r = $this->request();
        $transport = $this->recorder();
        $telegram = $this->notifier(static fn (): TransportInterface => $transport);

        self::assertTrue($settings->isTelegramOn(), 'on by default');
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertSame([], $transport->sent);
    }

    #[Test]
    public function aConfiguredTelegramStillSends(): void
    {
        self::bootKernel();
        $this->chatSet();
        $r = $this->request();
        $transport = $this->recorder();
        $telegram = $this->notifier(static fn (): TransportInterface => $transport);

        self::assertTrue($telegram->isConnected());
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->delivery);
        self::assertCount(1, $transport->sent);
        $options = $transport->sent[0]->getOptions();
        self::assertInstanceOf(TelegramOptions::class, $options);
        self::assertSame(self::CHAT, $options->toArray()['chat_id']);
        self::assertStringContainsString('№' . $r->id, (string) $transport->sent[0]->getSubject());
    }

    #[Test]
    public function aConfiguredTransportOfTheContainerIsConnected(): void
    {
        self::bootKernel(['framework' => self::NOTIFIER_TELEGRAM]);
        $this->chatSet();

        self::assertTrue(self::getContainer()->get(TelegramNotifier::class)->isConnected());
        self::assertTrue(self::getContainer()->get(TelegramNotifier::class)->isActive());
    }

    #[Test]
    public function theTelegramBridgeOfTheNotifierSends(): void
    {
        self::bootKernel();
        $this->chatSet();
        $r = $this->request();
        $http = new MockHttpClient(new MockResponse('{"ok": true, "result": {"message_id": 1}}'));
        $telegram = $this->notifier(static fn (): TransportInterface => new Transports(['telegram' => new TelegramTransport('123456:test-token', client: $http)]));

        self::assertTrue($telegram->isConnected());
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_SENT, $r->delivery);
        self::assertSame(1, $http->getRequestsCount());
    }

    #[Test]
    public function aNullTransportSendsNothingAndCountsAsNotConnected(): void
    {
        self::bootKernel(['framework' => self::NOTIFIER_NULL]); // TELEGRAM_DSN=null://null in the .env of a new site means «уведомления выключены»
        $this->chatSet();
        $r = $this->request();
        $telegram = self::getContainer()->get(TelegramNotifier::class);

        self::assertFalse($telegram->isConnected());
        self::assertFalse($telegram->isActive(), 'nothing is queued');
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery, 'never «Доставлено в Telegram»');

        $check = new TelegramHealthCheck($telegram, self::getContainer()->get(SettingsService::class), self::getContainer()->get(Schema::class));
        $this->setting('tgRoutes', []);
        self::assertSame(['ok', 'Telegram не подключён'], [$check->check()->status, $check->check()->message], 'a calm line even without a chat');
    }

    #[Test]
    public function aBareNullTransportIsNotConnectedEither(): void
    {
        self::bootKernel();

        self::assertFalse($this->notifier(static fn (): TransportInterface => new NullTransport())->isConnected());
    }

    #[Test]
    public function aRefusedMessageIsStillAFailureThatRetries(): void
    {
        self::bootKernel();
        $this->chatSet();
        $r = $this->request();
        $transport = $this->recorder(refuse: true);
        $telegram = $this->notifier(static fn (): TransportInterface => $transport);

        self::assertFalse($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_FAILED, $r->delivery);
        self::assertStringContainsString('Chat not found', (string) $r->deliveryError);
    }

    #[Test]
    public function aFailedRequestThatEndsUpPendingTakesItsNotificationBack(): void
    {
        self::bootKernel();
        $this->setting('tgOn', true);
        $r = $this->request();
        self::getContainer()->get('event_dispatcher')->dispatch(new RequestDelivery($r, false, 'refused'));
        $r->delivery = RequestEntity::DELIVERY_FAILED;
        $r->deliveryError = 'refused';
        $notifications = self::getContainer()->get(EntityManagerInterface::class)->getRepository(StoredNotification::class);
        self::assertSame(1, $notifications->count(['dedupeKey' => 'telegram.failed:' . $r->id]));

        self::assertTrue($this->notifier(null)->notify($r, true)); // Telegram was disconnected before the resend

        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertSame(0, $notifications->count(['dedupeKey' => 'telegram.failed:' . $r->id]), 'it is no failure any more, so the bell says nothing');
    }

    #[Test]
    public function aTransportThatCannotBeBuiltIsNoFailureAndItsDsnIsNotLogged(): void
    {
        self::bootKernel();
        $this->chatSet();
        $r = $this->request();
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = \strtr((string) $message, \array_map(static fn ($v): string => \is_scalar($v) ? (string) $v : '', \array_combine(\array_map(static fn (string $k): string => '{' . $k . '}', \array_keys($context)), $context)));
            }
        };
        $telegram = new TelegramNotifier(
            self::getContainer()->get(Schema::class),
            self::getContainer()->get(SettingsService::class),
            $logger,
            static fn (): never => throw new \InvalidArgumentException('The "telegram://123456:SECRET-token@default?channel=1" notifier DSN is invalid.'),
            self::getContainer()->get('event_dispatcher'),
            true,
        );

        self::assertFalse($telegram->isConnected());
        self::assertTrue($telegram->notify($r));
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertNotSame([], $logger->lines, 'a broken configuration is logged');
        self::assertStringNotContainsString('SECRET-token', \implode("\n", $logger->lines));
    }

    #[Test]
    public function theTestMessageButtonExplainsThatTelegramIsNotConnected(): void
    {
        self::bootKernel();
        $telegram = $this->notifier(static fn (): ?object => null);

        $this->expectException(\Itk\Cms\Content\ContentException::class);
        $this->expectExceptionMessage('Telegram не подключён на сайте');
        $telegram->sendTo(self::CHAT, 'проверка');
    }

    #[Test]
    public function theHealthLineWarnsOnlyWhenAConnectedTelegramHasNoChat(): void
    {
        self::bootKernel(['framework' => self::NOTIFIER_TELEGRAM]);
        $check = new TelegramHealthCheck(self::getContainer()->get(TelegramNotifier::class), self::getContainer()->get(SettingsService::class), self::getContainer()->get(Schema::class));

        $this->setting('tgOn', true);
        $this->setting('tgRoutes', []);
        self::assertSame('warn', $check->check()->status);
        self::assertStringContainsString('«Заявка на звонок»', $check->check()->message);

        $this->chatSet();
        self::assertSame(['ok', 'Telegram подключён, токен с сервера'], [$check->check()->status, $check->check()->message]);

        $this->setting('tgOn', false);
        self::assertSame('ok', $check->check()->status);
    }

    #[Test]
    public function theHealthLineIsCalmWhenTheBundleOptionSwitchesTelegramOff(): void
    {
        self::bootKernel();
        $this->setting('tgOn', true);
        $this->setting('tgRoutes', []);
        $transport = $this->recorder();
        $telegram = new TelegramNotifier(
            self::getContainer()->get(Schema::class),
            self::getContainer()->get(SettingsService::class),
            new NullLogger(),
            static fn (): TransportInterface => $transport,
            self::getContainer()->get('event_dispatcher'),
            false,
        );
        $check = new TelegramHealthCheck($telegram, self::getContainer()->get(SettingsService::class), self::getContainer()->get(Schema::class));

        self::assertSame('ok', $check->check()->status, 'no chat is needed when nothing is sent');
        self::assertStringContainsString('itk_cms.telegram.enabled', $check->check()->message);
    }
}

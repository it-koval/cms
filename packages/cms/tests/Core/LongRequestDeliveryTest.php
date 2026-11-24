<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Itk\Cms\Content\EditLock;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Request\MessageParts;
use Itk\Cms\Request\RequestDeliverer;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\LongText;
use Itk\Cms\Tests\Core\Fixture\MessengerApi;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Notifier\Transport\TransportInterface;

/** A long request arrives in Telegram and MAX as several messages that together are the whole text. */
final class LongRequestDeliveryTest extends WebTestCase
{
    private const CHATS = [Request::CHANNEL_TELEGRAM => '-1001234567890', Request::CHANNEL_MAX => '-70123456789012'];

    private MessengerApi $api;
    private TelegramNotifier $telegram;
    private MaxNotifier $max;
    /** @var array<string, array{bool, mixed}> */
    private array $saved = [];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, null, '', [], [], ['schema_file' => __DIR__ . '/Fixture/schema_long_requests.php']);
    }

    protected function setUp(): void
    {
        static::createClient();
        foreach (['tgOn' => true, 'tgRoutes' => ['*' => self::CHATS['telegram']], 'maxOn' => true, 'maxRoutes' => ['*' => self::CHATS['max']]] as $key => $value) {
            $this->setting($key, $value);
        }
        $this->api = $api = new MessengerApi();
        $c = self::getContainer();
        $config = (new MaxApiConfig('test-token-of-max'))->setHttpClient($api)->setRetryAttempts([]);
        $sender = new MaxApiSender(static fn (): MaxApiClient => new MaxApiClient($config), static fn (): MaxApiConfig => $config, new NullLogger());
        $this->telegram = new TelegramNotifier($c->get(Schema::class), $c->get(SettingsService::class), new NullLogger(), static fn (): TransportInterface => $api, $c->get('event_dispatcher'), true, $api->sleep(...));
        $this->max = new MaxNotifier($c->get(Schema::class), $c->get(SettingsService::class), new NullLogger(), $sender, $c->get('event_dispatcher'), true, $api->sleep(...));
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(Connection::class);
        foreach (['telegram.failed:', 'max.failed:'] as $prefix) {
            $db->executeStatement("DELETE FROM itk_notification WHERE dedupe_key IN (SELECT CAST(? AS TEXT) || id FROM itk_request WHERE form_key LIKE 'long_%')", [$prefix]);
        }
        $db->executeStatement("DELETE FROM itk_notification WHERE type = 'request.new' AND link IN (SELECT '/requests/' || id FROM itk_request WHERE form_key LIKE 'long_%')");
        $db->executeStatement("DELETE FROM itk_request WHERE form_key LIKE 'long_%'");
        $settings = self::getContainer()->get(SettingsService::class);
        foreach ($this->saved as $key => [$existed, $value]) {
            if ($existed) {
                $settings->set($key, $value);
            } else {
                $settings->remove($key);
            }
        }
        self::getContainer()->get(EntityManagerInterface::class)->createQuery("DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.email = 'test-f1-resend@example.com'")->execute();
        parent::tearDown();
    }

    private function setting(string $key, mixed $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->saved[$key] ??= [$settings->isSet($key), $settings->get($key)];
        $settings->set($key, $value);
    }

    private function notifier(string $channel): TelegramNotifier|MaxNotifier
    {
        return Request::CHANNEL_MAX === $channel ? $this->max : $this->telegram;
    }

    /** @param array<string, string> $data */
    private static function request(array $data, string $form = 'long_text', ?string $url = null): Request
    {
        $r = new Request($form);
        $r->data = $data;
        $r->sourceUrl = $url;

        return $r;
    }

    /** @return list<string> the messages the messenger took */
    private function sent(string $channel, Request $r): array
    {
        $outcome = $this->notifier($channel)->attempt($r);

        self::assertSame(Request::DELIVERY_SENT, $outcome->state, (string) $outcome->error);

        return $this->api->texts($channel);
    }

    /** @return iterable<array-key, mixed> */
    public static function channels(): iterable
    {
        yield 'Telegram' => [Request::CHANNEL_TELEGRAM];
        yield 'MAX' => [Request::CHANNEL_MAX];
    }

    /** @return iterable<array-key, mixed> */
    public static function lengths(): iterable
    {
        yield 'Telegram 4000' => ['telegram', 4000, 1];
        yield 'Telegram 4096' => ['telegram', 4096, 1];
        yield 'Telegram 4097' => ['telegram', 4097, 2];
        yield 'Telegram 12000' => ['telegram', 12000, 3];
        yield 'MAX 4000' => ['max', 4000, 1];
        yield 'MAX 4001' => ['max', 4001, 2];
        yield 'MAX 4096' => ['max', 4096, 2];
        yield 'MAX 4097' => ['max', 4097, 2];
        yield 'MAX 12000' => ['max', 12000, 4];
    }

    #[Test]
    #[DataProvider('lengths')]
    public function aTextArrivesWholeInMessagesWithinTheLimit(string $channel, int $length, int $messages): void
    {
        $text = LongText::order($length);
        $parts = $this->sent($channel, self::request(['items' => $text]));

        self::assertSame($text, \implode('', $parts), 'the messages together are the text');
        self::assertCount($messages, $parts);
        foreach (\array_slice($parts, 0, -1) as $part) {
            self::assertStringEndsWith("\n", $part, 'no line is broken');
        }
        self::assertStringEndsWith(LongText::TOTAL, $parts[$messages - 1]);
    }

    #[Test]
    #[DataProvider('channels')]
    public function aShortRequestIsOneMessageByteForByte(string $channel): void
    {
        $r = self::request(['head' => "  Отступ \"в кавычках\" & 'апостроф'\r\n", 'items' => '<Шлем> 😀'], 'long_bold');

        self::assertSame([$this->notifier($channel)->buildText($r)], $this->sent($channel, $r));
    }

    /** @return iterable<array-key, mixed> */
    public static function textsTelegramTakesInOneMessage(): iterable
    {
        yield 'entities count as one character' => [\str_repeat("'&<>\"", 819) . '!'];
        yield 'emoji counts as one character' => [\str_repeat('😀', 4096)];
        yield 'carriage returns do not count' => [\str_repeat("строка\r\n", 585) . '!'];
    }

    #[Test]
    #[DataProvider('textsTelegramTakesInOneMessage')]
    public function telegramMeasuresTheTextAfterReadingTheHtml(string $text): void
    {
        self::assertSame(4096, \mb_strlen(\str_replace("\r", '', $text)), 'the case is at the limit');
        $r = self::request(['items' => $text]);

        self::assertSame([$this->telegram->buildText($r)], $this->sent('telegram', $r));
    }

    /** @return iterable<array-key, mixed> */
    public static function whatTelegramTrimsOrDrops(): iterable
    {
        $full = \str_repeat('ж', 4096);
        yield 'a line break at the end' => [$full . "\n"];
        yield 'a line break at the start' => ["\n" . $full];
        yield 'two spaces at the end' => [$full . '  '];
        yield 'a line separator' => [\str_repeat('ж', 2000) . "\u{2028}" . \str_repeat('ж', 2096)];
        yield 'combining rings' => [\str_repeat("а\u{30A}", 30) . \str_repeat('ж', 4066)];
    }

    #[Test]
    #[DataProvider('whatTelegramTrimsOrDrops')]
    public function whatTelegramTrimsOrDropsDoesNotSplitAText(string $text): void
    {
        $r = self::request(['items' => $text]);

        self::assertSame([$this->telegram->buildText($r)], $this->sent('telegram', $r));
    }

    /** @return iterable<array-key, mixed> */
    public static function charactersThatArePrinted(): iterable
    {
        foreach (['private use' => "\u{E000}", 'a glyph of Symbol' => "\u{F0B7}", 'the last plane' => "\u{10FFFD}", 'newer than PCRE' => "\u{50000}"] as $name => $char) {
            foreach (['telegram', 'max'] as $channel) {
                yield $channel . ' ' . $name . ', a run' => [$channel, 'A' . \str_repeat($char, 9000) . 'Z'];
                yield $channel . ' ' . $name . ', the last line' => [$channel, LongText::order('max' === $channel ? 3999 : 4095) . "\n" . $char . $char];
            }
        }
    }

    #[Test]
    #[DataProvider('charactersThatArePrinted')]
    public function aPartOfPrivateOrUnknownCharactersIsNotDropped(string $channel, string $text): void
    {
        self::assertSame($text, \implode('', $this->sent($channel, self::request(['items' => $text]))));
    }

    /** @return iterable<array-key, mixed> */
    public static function runsTelegramTakesForNothing(): iterable
    {
        yield 'Braille blank' => ["\u{2800}"];
        yield 'object replacement' => ["\u{FFFC}"];
        yield 'zero width space' => ["\u{200B}"];
        yield 'deleted ring' => ["\u{30A}"];
        yield 'tag character' => ["\u{E0020}"];
    }

    #[Test]
    #[DataProvider('runsTelegramTakesForNothing')]
    public function aRunTelegramTakesForNothingDoesNotHoldBackTheTotal(string $char): void
    {
        $parts = $this->sent('telegram', self::request(['items' => "Начало\n" . \str_repeat($char, 9000) . "\n" . LongText::TOTAL]));

        self::assertStringContainsString('Начало', $parts[0]);
        self::assertStringEndsWith(LongText::TOTAL, $parts[\count($parts) - 1]);
    }

    #[Test]
    public function telegramGetsNoMessageOverItsLimitInBytes(): void
    {
        $text = \str_repeat('"', 4000) . \str_repeat("\r", 9000) . "\n" . LongText::order(300);
        $r = self::request(['items' => $text]);

        self::assertSame($this->telegram->buildText($r), \implode('', $this->sent('telegram', $r)));
    }

    #[Test]
    public function aLastPartOfASingleZeroIsStillAMessage(): void
    {
        $parts = $this->sent('telegram', self::request(['items' => \str_repeat('7', 4096) . '0']));

        self::assertSame(\str_repeat('7', 4096) . '0', \rtrim(\implode('', $parts)));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aTitleIsNotAMessageOfItsOwnWhenTheElementUnderItIsCutAnyway(string $channel): void
    {
        $parts = $this->sent($channel, self::request(['items' => LongText::order(6000)], 'long_title'));

        self::assertCount(2, $parts);
        self::assertGreaterThan(2000, \mb_strlen($parts[0]));
        self::assertStringEndsWith(LongText::TOTAL . '</i>', $parts[1]);
    }

    /** @return iterable<array-key, mixed> */
    public static function unusualTags(): iterable
    {
        foreach (['telegram', 'max'] as $channel) {
            yield $channel . ' > inside an attribute' => [$channel, 'long_quoted'];
        }
        yield 'telegram short end tag' => ['telegram', 'long_shut'];
    }

    #[Test]
    #[DataProvider('unusualTags')]
    public function anUnusualTagOfATemplateIsNotBroken(string $channel, string $form): void
    {
        $r = self::request(['items' => LongText::order(9000)], $form);
        $parts = $this->sent($channel, $r);

        self::assertSame(MessengerApi::plain($this->notifier($channel)->buildText($r)), \implode('', \array_map(MessengerApi::plain(...), $parts)));
    }

    #[Test]
    public function moreMessagesThanAGroupTakesInAMinuteStillArrive(): void
    {
        $text = LongText::order(90000);
        $parts = $this->sent('telegram', self::request(['items' => $text]));

        self::assertGreaterThan(20, \count($parts));
        self::assertSame($text, \implode('', $parts));
    }

    #[Test]
    public function maxIsNotSlowedDownToThePaceOfATelegramGroup(): void
    {
        $parts = $this->sent('max', self::request(['items' => LongText::order(90000)]));

        self::assertGreaterThan(20, \count($parts));
        self::assertEqualsWithDelta(\count($parts) - 1, $this->api->waited(), 0.01, 'a second between two messages');
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    public function aSignalDoesNotShortenThePauseBetweenMessages(): void
    {
        $parts = MessageParts::telegram(LongText::order(90000)); // over 20 messages: 3.1 seconds
        $async = \pcntl_async_signals(true);
        $handler = \pcntl_signal_get_handler(\SIGALRM);
        \pcntl_signal(\SIGALRM, static function (): void {}); // what messenger:consume --keepalive does every few seconds
        $start = \hrtime(true);
        try {
            \pcntl_alarm(1);
            $parts->pauseBefore(1);
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, $handler);
            \pcntl_async_signals($async);
        }

        self::assertGreaterThanOrEqual(3.09, (\hrtime(true) - $start) / 1e9);
    }

    /** @return iterable<array-key, mixed> */
    public static function longLines(): iterable
    {
        foreach (['Cyrillic' => 'ж', 'emoji' => '😀', 'ampersand' => '&', 'mixed' => "я😀&<\"'é"] as $name => $unit) {
            foreach (['telegram', 'max'] as $channel) {
                yield $channel . ' ' . $name => [$channel, \str_repeat($unit, \intdiv(9000, \mb_strlen($unit)))];
            }
        }
    }

    #[Test]
    #[DataProvider('longLines')]
    public function aLineLongerThanAMessageIsCutBetweenWholeCharactersAndEntities(string $channel, string $line): void
    {
        $r = self::request(['items' => $line]);
        $parts = $this->sent($channel, $r); // the API refuses half an entity, half a character and a message over the limit

        self::assertSame($this->notifier($channel)->buildText($r), \implode('', $parts));
        self::assertGreaterThan(1, \count($parts));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aMarkedElementThatFitsIsNotTornBetweenMessages(string $channel): void
    {
        $r = self::request(['head' => LongText::order(2500), 'items' => LongText::order(2500)], 'long_bold');
        $parts = $this->sent($channel, $r);

        self::assertSame($this->notifier($channel)->buildText($r), \implode('', $parts));
        self::assertCount(2, $parts);
        self::assertStringStartsWith('<b>', $parts[1]);
        self::assertStringEndsWith('</b>', $parts[1]);
    }

    #[Test]
    #[DataProvider('channels')]
    public function anElementLongerThanAMessageIsClosedAndOpenedAgain(string $channel): void
    {
        $r = self::request(['items' => LongText::order(12000)], 'long_marked', 'https://example.com/cart?' . \str_repeat('a', 400) . '&b=2');
        $parts = $this->sent($channel, $r); // the API refuses an unclosed tag

        self::assertSame(MessengerApi::plain($this->notifier($channel)->buildText($r)), \implode('', \array_map(MessengerApi::plain(...), $parts)));
        self::assertStringStartsWith('<a href="https://example.com/cart?', $parts[\count($parts) - 1]);
        self::assertStringEndsWith(LongText::TOTAL . '</i></a>', $parts[\count($parts) - 1]);
    }

    #[Test]
    public function markupThatNoMessageCanCarryGoesAsTextInAFewMessages(): void
    {
        $url = 'https://example.com/' . \str_repeat('a', 5000);
        $r = self::request(['items' => LongText::order(300)], 'long_marked', $url);

        $parts = $this->sent(Request::CHANNEL_MAX, $r);

        self::assertLessThanOrEqual(3, \count($parts), 'not one message per character');
        $all = \implode('', \array_map(MessengerApi::plain(...), $parts));
        self::assertStringContainsString($url, $all, 'the address is not lost');
        self::assertStringContainsString(LongText::TOTAL, $all);
    }

    #[Test]
    #[DataProvider('channels')]
    public function aFormWithoutATemplateIsDeliveredWholeToo(string $channel): void
    {
        $r = self::request(['items' => LongText::order(12000)], 'long_plain', 'https://example.com/cart');

        self::assertSame($this->notifier($channel)->buildText($r), \implode('', $this->sent($channel, $r)));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aRunOfEmptyLinesMakesNoEmptyMessage(string $channel): void
    {
        $parts = $this->sent($channel, self::request(['items' => 'Начало' . \str_repeat(" \n", 6000) . LongText::TOTAL])); // the API refuses a blank message

        self::assertStringContainsString('Начало', $parts[0]);
        self::assertStringEndsWith(LongText::TOTAL, $parts[\count($parts) - 1]);
    }

    #[Test]
    #[DataProvider('channels')]
    public function anyMixOfCharactersIsDeliveredWhole(string $channel): void
    {
        $units = ['я', 'a', "\u{E000}", "e\u{30A}", "\u{2028}", '😀', 'é', '&', '<', '>', '"', "'", '&amp;', '&#39;', '</b>', ' ', "\n", "\r\n", "\t"];
        \mt_srand(20261122);
        for ($case = 0; $case < 80; ++$case) {
            $text = '';
            for ($i = \mt_rand(1500, 9000); $i > 0; --$i) {
                $text .= $units[\mt_rand(0, \count($units) - 1)];
            }
            $r = self::request(['items' => 'x' . $text . 'x']);
            $this->api->forget();

            self::assertSame($this->notifier($channel)->buildText($r), \implode('', $this->sent($channel, $r)), 'case ' . $case);
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function anOrderOfTwentyPositionsGoesFromTheFormToTheChatWhole(string $channel): void
    {
        $items = LongText::cart(20);
        $client = static::getClient();
        $client->request('POST', '/request/long_order', ['name' => 'Иван', 'phone' => '+79000000011', 'items' => $items, 'consent' => '1']);
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = $em->getRepository(Request::class)->findOneBy(['form' => 'long_order'], ['id' => 'DESC']);
        $em->refresh($r);

        self::assertSame($items, $r->data['items']);
        self::assertSame($this->notifier($channel)->buildText($r), \implode('', $this->sent($channel, $r)));
        self::assertStringContainsString(LongText::TOTAL, \implode('', $this->api->texts($channel)));
    }

    #[Test]
    #[DataProvider('channels')]
    public function deliveryIsSavedBeforeAListenerThrows(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $events = $c->get('event_dispatcher');
        $r = self::request([], 'f2-delivery-' . \bin2hex(\random_bytes(5)));
        $em->persist($r);
        $em->flush();
        $id = (int) $r->id;
        $transaction = null;
        $listener = static function () use ($db, &$transaction): never {
            $transaction = $db->isTransactionActive();
            throw new \RuntimeException('F2 test listener failed');
        };
        $events->addListener(RequestDelivery::class, $listener, 100);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));

        try {
            self::assertTrue($deliverer->deliver($id, $channel, false));
            self::assertFalse($transaction, 'listeners run after the delivery transaction commits');
            $em->refresh($r);
            self::assertSame(Request::DELIVERY_SENT, $r->channelState($channel));
            self::assertTrue($deliverer->deliver($id, $channel, false));
            self::assertSame(1, $this->api->attempts);
        } finally {
            $events->removeListener(RequestDelivery::class, $listener);
            $db->delete('itk_request', ['id' => $id]);
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function anOuterRollbackCannotUndoDeliveryOrCauseAnotherSend(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $view = DriverManager::getConnection($db->getParams());
        $events = $c->get('event_dispatcher');
        $r = self::request([], 'f2-committed-delivery-' . \bin2hex(\random_bytes(5)));
        $em->persist($r);
        $em->flush();
        $id = (int) $r->id;
        $states = [];
        $observe = static function (RequestDelivery $event) use ($id, $view, &$states): void {
            if ($id === $event->request->id) {
                $states[] = $view->fetchOne('SELECT delivery FROM itk_request WHERE id = ?', [$id]);
            }
        };
        $events->addListener(RequestDelivery::class, $observe);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));

        try {
            $db->beginTransaction();
            self::assertTrue($deliverer->deliver($id, $channel, false));
            $db->rollBack();
            self::assertTrue($deliverer->deliver($id, $channel, false));
            self::assertSame(1, $this->api->attempts, 'a rollback elsewhere cannot cause a retry to send the request twice');
            self::assertSame([Request::DELIVERY_SENT], $states, 'the outcome is visible on another connection before listeners run');
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $events->removeListener(RequestDelivery::class, $observe);
            $view->close();
            $db->delete('itk_request', ['id' => $id]);
        }
    }

    #[Test]
    public function deliveryDuringAnAmbientTransactionRespectsRequestFilters(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $r = self::request([], 'f2-filtered-delivery-' . \bin2hex(\random_bytes(5)));
        $em->persist($r);
        $em->flush();
        $id = (int) $r->id;
        $filter = new class($em) extends SQLFilter {
            public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
            {
                return Request::class === $targetEntity->name ? $targetTableAlias . '.id <> ' . $this->getParameter('id') : '';
            }
        };
        $em->getConfiguration()->addFilter('f2_hide_request', $filter::class);
        $em->getFilters()->enable('f2_hide_request')->setParameter('id', $id);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));

        try {
            $db->beginTransaction();
            self::assertNull($deliverer->deliver($id, Request::CHANNEL_TELEGRAM, false));
            self::assertSame(0, $this->api->attempts);
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $em->getFilters()->disable('f2_hide_request');
            $db->delete('itk_request', ['id' => $id]);
        }
    }

    #[Test]
    public function deliveryFailureAndRecoveryUpdateTheBellOutsideTheAmbientTransaction(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $view = DriverManager::getConnection($db->getParams());
        $r = self::request([], 'f2-delivery-bell-' . \bin2hex(\random_bytes(5)));
        $em->persist($r);
        $em->flush();
        $id = (int) $r->id;
        $bellKey = 'telegram.failed:' . $id;
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $count = static fn (): int => (int) $view->fetchOne('SELECT COUNT(*) FROM itk_notification WHERE dedupe_key = ?', [$bellKey]);

        try {
            $db->beginTransaction();
            $this->api->refuseAttempt = 1;
            self::assertFalse($deliverer->deliver($id, Request::CHANNEL_TELEGRAM, false));
            self::assertSame(1, $count());
            $db->rollBack();
            self::assertSame(1, $count());

            $db->beginTransaction();
            self::assertTrue($deliverer->deliver($id, Request::CHANNEL_TELEGRAM, false));
            self::assertSame(0, $count());
            $db->rollBack();
            self::assertSame(0, $count());
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $view->close();
            $db->delete('itk_notification', ['dedupe_key' => $bellKey]);
            $db->delete('itk_request', ['id' => $id]);
        }
    }

    #[Test]
    public function theSameChannelIsReservedFromTheNetworkAttemptThroughTheBellUpdate(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $other = DriverManager::getConnection($db->getParams());
        $events = $c->get('event_dispatcher');
        $r = self::request([], 'f2-delivery-lock-' . \bin2hex(\random_bytes(5)));
        $em->persist($r);
        $em->flush();
        $id = (int) $r->id;
        $key = EditLock::key('request-delivery', $id . ':telegram');
        $lockChecks = [];
        $tryOtherWorker = static function () use ($key, $other, &$lockChecks): void {
            $available = (bool) $other->fetchOne('SELECT pg_try_advisory_lock(?)', [$key]);
            $lockChecks[] = $available;
            if ($available) {
                $other->fetchOne('SELECT pg_advisory_unlock(?)', [$key]);
            }
        };
        $this->api->beforeAttempt = $tryOtherWorker;
        $events->addListener(RequestDelivery::class, $tryOtherWorker);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));

        try {
            self::assertTrue($deliverer->deliver($id, Request::CHANNEL_TELEGRAM, false));
            $tryOtherWorker();
            self::assertSame([false, false, true], $lockChecks, 'the reservation covers the send and event and is then released');
        } finally {
            $this->api->beforeAttempt = null;
            $events->removeListener(RequestDelivery::class, $tryOtherWorker);
            $other->close();
            $db->delete('itk_request', ['id' => $id]);
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function afterARefusedSecondMessageTheRetryPreservesTheFirst(string $channel): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => LongText::order(12000)]);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class));
        $id = (int) $r->id;

        $this->api->refuseAttempt = 2;
        self::assertFalse($deliverer->deliver($id, $channel, false), 'the queue retries it');
        $first = $this->api->texts($channel);
        self::assertCount(1, $first, 'nothing follows the refused message');
        self::assertSame(2, $this->api->attempts);
        self::assertSame(Request::DELIVERY_FAILED, $r->channelState($channel));
        self::assertSame(Request::DELIVERY_FAILED, $r->delivery);
        self::assertStringContainsString('часть 2 из', (string) $r->channelError($channel));
        self::assertStringContainsString('test refusal', (string) $r->channelError($channel));

        $this->api->forget();
        self::assertTrue($deliverer->deliver($id, $channel, false), 'the retry of the queue');
        $retry = $this->api->texts($channel);
        self::assertSame($this->notifier($channel)->buildText($r), \implode('', [...$first, ...$retry]));
        self::assertSame(Request::DELIVERY_SENT, $r->channelState($channel));

        $this->api->forget();
        self::assertTrue($deliverer->deliver($id, $channel, true), '«Отправить ещё раз»');
        $again = $this->api->texts($channel);
        self::assertStringContainsString('доставляется повторно', $again[0]);
        self::assertSame($this->notifier($channel)->buildText($r, true), \implode('', $again));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aRefusedChatDoesNotStopTheNextOne(string $channel): void
    {
        $this->setting(Request::CHANNEL_MAX === $channel ? 'maxRoutes' : 'tgRoutes', ['*' => self::CHATS[$channel] . ', -1009999999999']);
        $this->api->refuseAttempt = 2;
        $r = self::request(['items' => LongText::order(12000)]);

        $outcome = $this->notifier($channel)->attempt($r);

        self::assertTrue($outcome->isFailed());
        self::assertStringContainsString(self::CHATS[$channel], (string) $outcome->error);
        self::assertSame($this->notifier($channel)->buildText($r), \implode('', $this->api->texts($channel, '-1009999999999')));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aShortRequestThatFailedKeepsItsErrorAsItWas(string $channel): void
    {
        $this->api->refuseAttempt = 1;
        $notifier = $this->notifier($channel);

        $outcome = $notifier->attempt(self::request(['items' => 'Шлем']));

        self::assertTrue($outcome->isFailed());
        self::assertStringStartsWith(self::CHATS[$channel] . ': ', (string) $outcome->error);
        self::assertStringNotContainsString('часть', (string) $outcome->error);
    }

    #[Test]
    #[DataProvider('f1ResendSizes')]
    public function f1ResendUsesTheSelectedChannelActualParts(string $channel, int $parts): void
    {
        $limit = 'telegram' === $channel ? 4096 : 4000;
        $r = self::request(['items' => \str_repeat('x', $limit * ($parts - 1) - 100)], 'long_f1_resend');
        $r->markChannel($channel, Request::DELIVERY_FAILED, 'earlier test refusal');
        self::assertCount($parts, $this->notifier($channel)->parts($r, true)->texts, 'the actual resend formatter defines the boundary');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $queued = [];
        $middleware = new class($queued) implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            /** @param list<object> $queued */
            public function __construct(public array &$queued)
            {
            }

            public function handle(\Symfony\Component\Messenger\Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): \Symfony\Component\Messenger\Envelope
            {
                $this->queued[] = $envelope->getMessage();

                return $envelope;
            }
        };
        $service = $this->f1Service(new \Symfony\Component\Messenger\MessageBus([$middleware]));
        $result = $service->resendResult((int) $r->id, $channel);
        self::assertSame($parts > 20 ? [$channel] : [], $result['queuedChannels']);
        if ($parts > 20) {
            self::assertCount(1, $queued);
            self::assertSame($channel, $queued[0]->channel);
            self::assertSame((int) $r->id, $queued[0]->requestId);
            self::assertSame([], $this->api->texts($channel), 'HTTP did not deliver');
            (new \Itk\Cms\Request\SendRequestNotificationHandler(new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class))))($queued[0]);
        }
        self::assertCount($parts, $this->api->texts($channel));
        self::assertSame(Request::DELIVERY_SENT, $r->channelState($channel));
    }

    /** @return iterable<array-key, mixed> */
    public static function f1ResendSizes(): iterable
    {
        foreach (['telegram', 'max'] as $channel) {
            foreach ([20, 21] as $parts) {
                yield $channel . $parts => [$channel, $parts];
            }
        }
    }

    #[Test]
    public function f1PartialDispatchReturnsEveryOutcomeAndCannotQueueTheFirstChannelTwice(): void
    {
        $r = self::request(['items' => \str_repeat('x', 85000)], 'long_f1_resend');
        foreach (['telegram', 'max'] as $channel) {
            $r->markChannel($channel, Request::DELIVERY_FAILED, 'earlier refusal');
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $messages = [];
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function ($message) use (&$messages) {
            if ('max' === $message->channel) {
                throw new \RuntimeException('test refusal');
            }
            $messages[] = $message;

            return new \Symfony\Component\Messenger\Envelope($message);
        });
        $service = $this->f1Service($bus);
        $result = $service->resendResult((int) $r->id);
        self::assertSame(['telegram', 'max'], $result['queuedChannels']);
        self::assertSame('queued', $result['outcomes']['max']['state']);
        $em->refresh($r);
        self::assertSame(['telegram', 'max'], $service->card($r)['queuedChannels'], 'reopening the card preserves the queue state');
        $service->resendResult((int) $r->id);
        self::assertCount(1, $messages, 'a partial failure cannot enqueue the accepted channel again');
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler(new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class)));
        $handler($messages[0]);
        $count = \count($this->api->texts('telegram'));
        $handler($messages[0]);
        self::assertCount($count, $this->api->texts('telegram'), 'redelivery of the accepted job is idempotent');
        self::assertSame(['max'], $service->card($r)['queuedChannels'], 'the uncertain publication remains recoverable');
    }

    #[Test]
    public function f1AQueueFailureReturnsTheEarlierSynchronousDeliveryToo(): void
    {
        $r = self::request(['items' => \str_repeat('&', 16400)], 'long_f1_resend');
        foreach (['telegram', 'max'] as $channel) {
            $r->markChannel($channel, Request::DELIVERY_FAILED, 'earlier refusal');
        }
        self::assertLessThanOrEqual(20, \count($this->telegram->parts($r, true)->texts));
        self::assertGreaterThan(20, \count($this->max->parts($r, true)->texts));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('test refusal'));
        $service = $this->f1Service($bus);
        $out = $service->resendResult((int) $r->id);
        self::assertSame('sent', $out['outcomes']['telegram']['state']);
        self::assertSame('queued', $out['outcomes']['max']['state']);
        self::assertSame(202, $out['status']);
        $count = \count($this->api->texts('telegram'));
        $again = $service->resendResult((int) $r->id);
        self::assertArrayNotHasKey('telegram', $again['outcomes']);
        self::assertCount($count, $this->api->texts('telegram'));
    }

    #[Test]
    #[DataProvider('f1SynchronousOutcomes')]
    public function f1SynchronousBusReturnsTheDeliveryOutcomeInsteadOfQueueAcceptance(bool $refused, bool $otherQueued = false): void
    {
        if ($refused) {
            $this->api->refuseAttempt = 1;
        }
        $r = self::request(['items' => \str_repeat('x', 85000)], 'long_f1_resend');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        if ($otherQueued) {
            self::assertNotNull(RequestDeliverer::reserve($em, (int) $r->id, 'max', false));
        }
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler(new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class)));
        $handlers = new \Symfony\Component\Messenger\Handler\HandlersLocator([\Itk\Cms\Request\SendRequestNotification::class => [$handler]]);
        $sync = new \Symfony\Component\Messenger\Transport\Sync\SyncTransport(new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware($handlers)]));
        $send = new class($sync) implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function __construct(private readonly \Symfony\Component\Messenger\Transport\Sync\SyncTransport $transport)
            {
            }

            public function handle(\Symfony\Component\Messenger\Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): \Symfony\Component\Messenger\Envelope
            {
                return $this->transport->send($envelope);
            }
        };
        $bus = new \Symfony\Component\Messenger\MessageBus([$send]);
        $out = $this->f1Service($bus)->resendResult((int) $r->id, 'telegram');
        self::assertSame($otherQueued ? ['max'] : [], $out['queuedChannels']);
        self::assertSame($refused ? 'failed' : 'sent', $out['outcomes']['telegram']['state']);
        self::assertSame(200, $out['status'], 'a handled refusal is not a failed enqueue');
        self::assertSame($refused ? 'failed' : 'done', $r->channelJobPhase('telegram'));
        if ($refused) {
            self::assertStringContainsString('test refusal', $out['outcomes']['telegram']['error']);
            self::assertStringContainsString('часть 1 из', $out['outcomes']['telegram']['error']);
        }
    }

    /** @return iterable<array-key, mixed> */
    public static function f1SynchronousOutcomes(): iterable
    {
        yield 'sent synchronously' => [false];
        yield 'handler refused after recording the real error' => [true];
        yield 'existing queued job in another channel' => [false, true];
    }

    #[Test]
    public function f1DeliveryListenerRunsAfterCommitAndCannotCauseASecondSend(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'after-commit-regression']);
        $em->persist($r);
        $em->flush();
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, 'telegram', $job);
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler(new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class)));
        $seen = false;
        $listener = static function ($event) use ($em, &$seen): void {
            self::assertFalse($em->getConnection()->isTransactionActive());
            $stored = \json_decode($em->getConnection()->fetchOne('SELECT channels FROM itk_request WHERE id = ?', [$event->request->id]), true);
            self::assertSame('sent', $stored['telegram']['state']);
            $seen = true;
            throw new \RuntimeException('test extension failure after delivery');
        };
        $dispatcher = $c->get('event_dispatcher');
        $dispatcher->addListener(\Itk\Cms\Event\RequestDelivery::class, $listener);
        try {
            $handler($message);
            self::assertTrue($seen);
            self::assertTrue($em->isOpen());
            $handler($message);
            self::assertCount(1, $this->api->texts('telegram'));
            self::assertSame(Request::DELIVERY_SENT, $r->channelState('telegram'));
        } finally {
            $dispatcher->removeListener(\Itk\Cms\Event\RequestDelivery::class, $listener);
        }
    }

    #[Test]
    public function f1FinalFailureReleasesTheActionAndStaleJobsCannotRevive(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'final-failure-regression']);
        $r->markChannel('telegram', Request::DELIVERY_FAILED, 'earlier refusal');
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class));
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', true);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true, 'telegram', $job);
        $retry = new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(new \Symfony\Component\Messenger\Envelope($message), 'async', new \RuntimeException('retryable refusal'));
        $retry->setForRetry();
        (new \Itk\Cms\Request\RequestDeliveryJobListener($deliverer))($retry);
        self::assertSame(['telegram'], $r->queuedChannels(), 'a scheduled retry keeps the reservation active');
        $failure = new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(new \Symfony\Component\Messenger\Envelope($message), 'async', new \RuntimeException('terminal refusal'));
        (new \Itk\Cms\Request\RequestDeliveryJobListener($deliverer))($failure);
        self::assertSame([], $r->queuedChannels());
        self::assertTrue($deliverer->resumeJob($message), 'explicit failed queue retry rearms the same token');
        self::assertSame(['telegram'], $r->queuedChannels());
        $deliverer->queueFailed((int) $r->id, 'telegram', $job, 'refused again');
        $newJob = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', true);
        self::assertNotSame($job, $newJob);
        self::assertFalse($deliverer->resumeJob($message), 'a new operator action supersedes the old failed job');
        (new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer))($message);
        self::assertSame([], $this->api->texts('telegram'));
        self::assertSame(['telegram'], $r->queuedChannels());
    }

    #[Test]
    public function integrationCanQueueANewResendWhileOldMessagesRemainReplays(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'integration-resend']);
        $em->persist($r);
        $em->flush();
        $bus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            /** @var list<\Itk\Cms\Request\SendRequestNotification> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                \assert($message instanceof \Itk\Cms\Request\SendRequestNotification);
                $this->messages[] = $message;

                return new \Symfony\Component\Messenger\Envelope($message, $stamps);
            }
        };
        $queue = new \Itk\Cms\Request\RequestQueue($em, $bus, new NullLogger());
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler(new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class)));
        $queue->enqueue((int) $r->id, 'telegram');
        $initial = $bus->messages[0];
        $handler($initial);
        self::assertCount(1, $this->api->texts('telegram'));

        $queue->enqueue((int) $r->id, 'telegram', resend: true);
        $resend = $bus->messages[1];
        self::assertNotSame($initial->jobId, $resend->jobId);
        $queue->enqueue((int) $r->id, 'telegram', resend: true);
        self::assertCount(2, $bus->messages, 'a live reservation coalesces repeated integration calls');
        $handler($initial);
        $handler(new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true, 'telegram'));
        self::assertCount(1, $this->api->texts('telegram'), 'old identities cannot masquerade as a new resend');
        $handler($resend);
        $handler($resend);
        self::assertCount(2, $this->api->texts('telegram'));
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$r->id]));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aLegacyResendOfAnAlreadySentRequestWaitsForOperatorVerification(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => '0.2.2 resend without an operation identity']);
        $r->markChannel($channel, Request::DELIVERY_SENT);
        $em->persist($r);
        $em->flush();
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class), $logger);
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true, $channel);

        $handler($message);
        $em->refresh($r);
        self::assertSame([], $this->api->texts($channel), 'a 0.2.2 replay must never become a fresh resend');
        self::assertSame('uncertain', $r->channelJobPhase($channel));
        self::assertNotSame('legacy', $r->channelJob($channel));
        self::assertSame('failed', $r->channelState($channel));
        self::assertNotEmpty($r->channelError($channel));
        self::assertSame([], $r->channelParts($channel), 'historical recipients and parts must never be guessed');
        self::assertSame('legacy-resend-quarantine', $r->channelJob($channel));
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame($r->id, $logger->records[0][2]['requestId']);
        self::assertSame($channel, $logger->records[0][2]['channel']);
        $quarantined = $em->getConnection()->fetchOne('SELECT channels FROM itk_request WHERE id = ?', [$r->id]);
        $handler($message);
        self::assertFalse($deliverer->resumeJob($message));
        self::assertSame($quarantined, $em->getConnection()->fetchOne('SELECT channels FROM itk_request WHERE id = ?', [$r->id]), 'duplicate redelivery must preserve the quarantine');
        self::assertCount(1, $logger->records);
        self::assertSame([], $this->api->texts($channel));
    }

    #[Test]
    #[DataProvider('legacyResendResolutions')]
    public function aLegacyResendQuarantineHasAnExplicitChannelScopeResolution(string $channel, bool $accepted, bool $preflight): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'historical recipients and text were never checkpointed']);
        $r->markChannel($channel, Request::DELIVERY_SENT);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true, $channel);
        if ($preflight) {
            self::assertFalse($deliverer->resumeJob($message));
        } else {
            $handler($message);
        }
        self::assertSame([], $this->api->texts($channel));
        self::assertSame('uncertain', $r->channelJobPhase($channel));
        $key = $channel . '.failed:' . $r->id;
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT count(*) FROM itk_notification WHERE dedupe_key = ?', [$key]), 'quarantine appears in the existing operator bell');
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Itk\Cms\Command\ResolveRequestDeliveryCommand($deliverer));
        self::assertSame(1, $tester->execute(['request' => $r->id, 'channel' => $channel, 'recipient' => self::CHATS[$channel], 'outcome' => 'sent']), 'a guessed current recipient cannot verify a historical channel');
        self::assertSame(0, $tester->execute(['request' => $r->id, 'channel' => $channel, 'recipient' => '*', 'outcome' => $accepted ? 'sent' : 'rejected']));
        self::assertSame([], $this->api->texts($channel), 'resolution never sends');
        $handler($message);
        self::assertFalse($deliverer->resumeJob($message));
        self::assertSame([], $this->api->texts($channel), 'old envelope stays inert after operator resolution');
        self::assertSame($accepted ? 'sent' : 'failed', $r->channelState($channel));
        self::assertSame($accepted ? 'done' : 'failed', $r->channelJobPhase($channel));
        self::assertSame($accepted ? 0 : 1, (int) $em->getConnection()->fetchOne('SELECT count(*) FROM itk_notification WHERE dedupe_key = ?', [$key]));
        self::assertSame(1, $tester->execute(['request' => $r->id, 'channel' => $channel, 'recipient' => '*', 'outcome' => 'rejected']), 'channel-scope resolution cannot change a completed judgment');
        if (!$accepted) {
            $deliverer->deliverSynchronously((int) $r->id, $channel, true);
            self::assertCount(1, $this->api->texts($channel), 'only an explicit fresh resend is released after rejection verification');
            self::assertSame('sent', $r->channelState($channel));
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function legacyResendResolutions(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            foreach ([false, true] as $preflight) {
                yield $channel . ' sent ' . (int) $preflight => [$channel, true, $preflight];
                yield $channel . ' rejected ' . (int) $preflight => [$channel, false, $preflight];
            }
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function aLegacyResendStillDeliversAnUndeliveredRequestOnlyOnce(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => '0.2.2 resend which never reached the messenger']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true, $channel);

        self::assertTrue($deliverer->resumeJob($message));
        $handler($message);
        $handler($message);

        self::assertCount(1, $this->api->texts($channel));
        self::assertSame('sent', $r->channelState($channel));
        self::assertSame('done', $r->channelJobPhase($channel));
        self::assertSame('legacy', $r->channelJob($channel));
        self::assertFalse($deliverer->resumeJob($message));
    }

    #[Test]
    public function aLegacyResendAlsoQuarantinesTheHistoricalTelegramAggregate(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'row predating channel metadata']);
        $r->delivery = Request::DELIVERY_SENT;
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));

        (new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer))(new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true));

        self::assertSame([], $this->api->texts('telegram'));
        self::assertSame('uncertain', $r->channelJobPhase('telegram'));
        self::assertSame('legacy-resend-quarantine', $r->channelJob('telegram'));
        self::assertNull($r->channelTexts('telegram'));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aLegacyResendPreservesRealInterruptedPartsForRecipientResolution(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'known interrupted checkpoint without job metadata']);
        $texts = $this->notifier($channel)->parts($r)->texts;
        $r->markChannel($channel, Request::DELIVERY_SENT);
        $r->markChannelTexts($channel, $texts);
        $partKey = \hash('sha256', self::CHATS[$channel] . ':0');
        $r->markChannelPart($channel, $partKey, ['hash' => \hash('sha256', $texts[0]), 'state' => 'sending']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $handler = new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, true, $channel);

        $handler($message);

        self::assertSame([], $this->api->texts($channel));
        self::assertSame('uncertain', $r->channelJobPhase($channel));
        self::assertSame($texts, $r->channelTexts($channel));
        self::assertSame('sending', $r->channelParts($channel)[$partKey]['state']);
        self::assertFalse($deliverer->resolve((int) $r->id, $channel, '*', true), 'channel-scope judgment must not erase a real unknown part');
        self::assertTrue($deliverer->resolve((int) $r->id, $channel, self::CHATS[$channel], true));
        $handler($message);
        $deliverer->deliverSynchronously((int) $r->id, $channel, true);
        self::assertSame([], $this->api->texts($channel), 'the actual accepted checkpoint remains skipped');
        self::assertSame('sent', $r->channelState($channel));
    }

    #[Test]
    public function bulkRequeueCountsOnlyRequestsWithAStagedJob(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $requests = [];
        foreach (['uncertain', 'queued', 'failed'] as $phase) {
            $r = self::request(['items' => 'bulk-count-' . $phase]);
            $r->markChannel('telegram', Request::DELIVERY_FAILED, 'test');
            $r->markChannelJob('telegram', 'bulk-count-' . $phase, $phase, 'queued' === $phase ? \time() + 3600 : 0);
            $em->persist($r);
            $requests[$phase] = $r;
        }
        $em->flush();
        $transport = $c->get('messenger.transport.async');
        \assert($transport instanceof \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport);
        $transport->reset();

        $count = $c->get(\Itk\Cms\Request\RequestService::class)->requeueFailed();

        $ids = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof \Itk\Cms\Request\SendRequestNotification) {
                $ids[$message->requestId] = true;
            }
        }
        self::assertArrayHasKey($requests['failed']->id, $ids);
        self::assertArrayNotHasKey($requests['uncertain']->id, $ids);
        self::assertArrayNotHasKey($requests['queued']->id, $ids);
        self::assertSame(\count($ids), $count);
    }

    #[Test]
    public function f1TerminalListenerWithAClosedManagerPreservesTheWorkerFailure(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'closed-manager-regression']);
        $em->persist($r);
        $em->flush();
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false);
        $isolated = new \Doctrine\ORM\EntityManager($em->getConnection(), $em->getConfiguration());
        $isolated->close();
        $deliverer = new RequestDeliverer($isolated, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, 'telegram', $job);
        $original = new \RuntimeException('original failed flush');
        $failure = new \Symfony\Component\Messenger\Event\WorkerMessageFailedEvent(new \Symfony\Component\Messenger\Envelope($message), 'async', $original);
        (new \Itk\Cms\Request\RequestDeliveryJobListener($deliverer))($failure);
        self::assertSame($original, $failure->getThrowable());
        self::assertFalse($failure->willRetry());
        $em->refresh($r);
        self::assertSame('queued', $r->channelJobPhase('telegram'));
        self::assertGreaterThan(\time(), $r->channelJobLease('telegram'));
        self::assertLessThanOrEqual(\time() + 3600, $r->channelJobLease('telegram'));
        self::assertFalse($em->getConnection()->isTransactionActive());
    }

    #[Test]
    public function f1AnExpiredReservationCanBeReclaimedWithoutSendingItsOldJob(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'expired-reservation-regression']);
        $r->markChannelJob('telegram', 'expired', 'queued', \time() - 1);
        $em->persist($r);
        $em->flush();
        self::assertSame([], $r->queuedChannels());
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false);
        self::assertNotNull($job);
        self::assertNotSame('expired', $job);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class));
        self::assertTrue($deliverer->deliver((int) $r->id, 'telegram', false, 'expired'));
        self::assertSame([], $this->api->texts('telegram'));
    }

    #[Test]
    public function synchronousResendCommitsWhileTheCallerCanStillRollBack(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'short synchronous resend']);
        $em->persist($r);
        $em->flush();
        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $this->f1Service(new \Symfony\Component\Messenger\MessageBus())->resendResult((int) $r->id, 'telegram');
            self::assertSame('sent', $result['outcomes']['telegram']['state']);
        } finally {
            $connection->rollBack();
        }
        $em->refresh($r);
        self::assertSame('done', $r->channelJobPhase('telegram'));
        self::assertCount(1, $this->api->texts('telegram'));
    }

    #[Test]
    public function aStaleFailureCannotReserveAnotherSendAfterTheWorkerSucceeded(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'a retry went out before the operator sent again']);
        $r->markChannel('telegram', Request::DELIVERY_FAILED, 'earlier refusal');
        $em->persist($r);
        $em->flush();
        $other = DriverManager::getConnection($em->getConnection()->getParams());
        try {
            $other->executeStatement("UPDATE itk_request SET channels = '{\"telegram\":{\"state\":\"sent\",\"job\":\"completed\",\"phase\":\"done\"}}', delivery = 'sent', delivery_error = NULL WHERE id = ?", [$r->id]);
            $result = $this->f1Service(new \Symfony\Component\Messenger\MessageBus())->resendResult((int) $r->id, 'telegram');
            self::assertSame('sent', $result['outcomes']['telegram']['state']);
            self::assertSame('completed', $r->channelJob('telegram'));
            self::assertSame([], $this->api->texts('telegram'));
        } finally {
            $other->close();
        }
    }

    #[Test]
    public function anExpiredLeaseCannotReplaceAWorkerThatStillHoldsTheChannelLock(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'worker running past lease']);
        $em->persist($r);
        $em->flush();
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false);
        $connection = DriverManager::getConnection($em->getConnection()->getParams());
        $other = new \Doctrine\ORM\EntityManager($connection, $em->getConfiguration());
        $replacement = null;
        $this->api->beforeAttempt = static function () use ($connection, $other, $r, &$replacement): void {
            $connection->executeStatement("UPDATE itk_request SET channels = jsonb_set(channels, '{telegram,lease}', '0') WHERE id = ?", [$r->id]);
            $replacement = RequestDeliverer::reserve($other, (int) $r->id, 'telegram', true);
        };
        try {
            $this->dispatchDelivery(new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class)), new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, 'telegram', $job));
        } finally {
            $this->api->beforeAttempt = null;
            $connection->close();
        }
        $em->refresh($r);
        self::assertNull($replacement);
        self::assertSame($job, $r->channelJob('telegram'));
        self::assertSame('sent', $r->channelState('telegram'));
        self::assertCount(1, $this->api->texts('telegram'));
    }

    #[Test]
    #[DataProvider('deliveryCrashes')]
    public function redeliveryAfterProviderAcceptanceNeverSendsTheSamePartAgain(string $channel, bool $beforePartCheckpoint): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'provider accepted before worker stopped']);
        $em->persist($r);
        $em->flush();
        $job = RequestDeliverer::reserve($em, (int) $r->id, $channel, false);
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, $channel, $job);
        $connection = DriverManager::getConnection($em->getConnection()->getParams());
        $events = new \Doctrine\Common\EventManager();
        $events->addEventListener([\Doctrine\ORM\Events::onFlush], new class($channel, $beforePartCheckpoint) {
            public function __construct(private readonly string $channel, private readonly bool $beforePartCheckpoint)
            {
            }

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $event): void
            {
                foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if (!$entity instanceof Request) {
                        continue;
                    }
                    $aboutToCommit = $this->beforePartCheckpoint
                        ? \in_array('sent', \array_column($entity->channelParts($this->channel), 'state'), true)
                        : 'done' === $entity->channelJobPhase($this->channel);
                    if ($aboutToCommit) {
                        throw new \RuntimeException('worker stopped before outcome commit');
                    }
                }
            }
        });
        $crashing = new \Doctrine\ORM\EntityManager($connection, $em->getConfiguration(), $events);
        try {
            $this->dispatchDelivery(new RequestDeliverer($crashing, $this->telegram, $this->max, $c->get(AdminLocale::class)), $message);
            self::fail('the simulated worker should stop before the outcome commits');
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $failure) {
            self::assertSame('worker stopped before outcome commit', $failure->getPrevious()?->getMessage());
        } finally {
            $connection->close();
        }
        self::assertCount(1, $this->api->texts($channel));
        $em->getConnection()->executeStatement("UPDATE itk_request SET channels = jsonb_set(channels, ARRAY[?, 'lease'], '0') WHERE id = ?", [$channel, $r->id]);
        $this->dispatchDelivery(new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class)), $message);
        $em->refresh($r);
        self::assertSame($beforePartCheckpoint ? 'failed' : 'sent', $r->channelState($channel));
        self::assertSame($beforePartCheckpoint ? 'uncertain' : 'done', $r->channelJobPhase($channel));
        self::assertCount(1, $this->api->texts($channel));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function deliveryCrashes(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            yield $channel . ' before part checkpoint' => [$channel, true];
            yield $channel . ' before final outcome' => [$channel, false];
        }
    }

    #[Test]
    public function redeliveryReplaysTheOutcomeAfterAWorkerStopsFollowingCommit(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'worker stopped before announcing']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $message = new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, 'telegram', RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false));
        $this->api->refuseAttempt = 1;
        try {
            $this->dispatchDelivery($deliverer, $message);
            self::fail('the first attempt should be refused');
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $failure) {
            self::assertInstanceOf(\Itk\Cms\Request\RequestNotDeliveredException::class, $failure->getPrevious());
        }
        $connection = $em->getConnection();
        $key = 'telegram.failed:' . $r->id;
        self::assertSame(1, (int) $connection->fetchOne('SELECT count(*) FROM itk_notification WHERE dedupe_key = ?', [$key]));
        $dispatcher = $c->get('event_dispatcher');
        $stop = static function (): never {
            throw new \RuntimeException('worker stopped before the first delivery listener');
        };
        $dispatcher->addListener(RequestDelivery::class, $stop, 1000);
        try {
            $this->dispatchDelivery($deliverer, $message);
        } finally {
            $dispatcher->removeListener(RequestDelivery::class, $stop);
        }
        $em->refresh($r);
        self::assertSame('done', $r->channelJobPhase('telegram'));
        $this->dispatchDelivery($deliverer, $message);
        self::assertCount(1, $this->api->texts('telegram'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM itk_notification WHERE dedupe_key = ?', [$key]));
    }

    #[Test]
    public function outcomeRecoveryKeepsARefusalAndALaterSuccessInOrder(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'both outcome events survive a broken listener']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false);
        $dispatcher = $c->get('event_dispatcher');
        $stop = static function (): never {
            throw new \RuntimeException('temporarily unavailable listener');
        };
        $dispatcher->addListener(RequestDelivery::class, $stop, 1000);
        try {
            $this->api->refuseAttempt = 1;
            self::assertFalse($deliverer->deliver((int) $r->id, 'telegram', false, $job));
            self::assertTrue($deliverer->deliver((int) $r->id, 'telegram', false, $job));
            self::assertSame(0, $deliverer->recoverAnnouncements());
        } finally {
            $dispatcher->removeListener(RequestDelivery::class, $stop);
        }
        $em->getConnection()->executeStatement("UPDATE itk_request SET channels = channels #- '{telegram,announcementRetryAt}' WHERE id = ?", [$r->id]);
        $seen = [];
        $listener = static function (RequestDelivery $event) use ($r, &$seen): void {
            if ($event->request->id === $r->id) {
                $seen[] = $event->delivered;
            }
        };
        $dispatcher->addListener(RequestDelivery::class, $listener);
        try {
            self::assertSame(1, $deliverer->recoverAnnouncements());
            self::assertSame([false, true], $seen);
            self::assertSame(0, $deliverer->recoverAnnouncements());
        } finally {
            $dispatcher->removeListener(RequestDelivery::class, $listener);
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function repeatedChatIdsReceiveOnlyOneCopy(string $channel): void
    {
        $chat = self::CHATS[$channel];
        $this->setting('telegram' === $channel ? 'tgRoutes' : 'maxRoutes', ['*' => $chat . ', ' . $chat]);
        self::assertCount(1, $this->sent($channel, self::request(['items' => 'one configured destination'])));
    }

    #[Test]
    public function autoCommitDisabledOnAnUnopenedConnectionStillAnnouncesAfterCommit(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'delivery with DBAL auto-commit disabled']);
        $em->persist($r);
        $em->flush();
        $job = RequestDeliverer::reserve($em, (int) $r->id, 'telegram', false);
        $connection = DriverManager::getConnection($em->getConnection()->getParams());
        $connection->setAutoCommit(false);
        self::assertFalse($connection->isConnected());
        self::assertFalse($connection->isTransactionActive());
        $other = new \Doctrine\ORM\EntityManager($connection, $em->getConfiguration());
        $seen = null;
        $listener = static function (RequestDelivery $event) use ($em, &$seen): void {
            $seen = $em->getConnection()->fetchOne("SELECT channels->'telegram'->>'state' FROM itk_request WHERE id = ?", [$event->request->id]);
        };
        $dispatcher = $c->get('event_dispatcher');
        $dispatcher->addListener(RequestDelivery::class, $listener);
        try {
            $this->dispatchDelivery(new RequestDeliverer($other, $this->telegram, $this->max, $c->get(AdminLocale::class)), new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, 'telegram', $job));
        } finally {
            $dispatcher->removeListener(RequestDelivery::class, $listener);
            $connection->close();
        }
        self::assertSame('sent', $seen);
        $em->refresh($r);
        self::assertSame('done', $r->channelJobPhase('telegram'));
        self::assertCount(1, $this->api->texts('telegram'));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aResendCannotResolveAnUncertainDelivery(string $channel): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => LongText::order(12000)]);
        $parts = $this->notifier($channel)->parts($r)->texts;
        $r->markChannel($channel, Request::DELIVERY_FAILED, 'response lost for part two');
        $r->markChannelJob($channel, 'operator-uncertain', 'uncertain');
        $r->markChannelResend($channel, false);
        foreach ([0 => 'sent', 1 => 'sending'] as $index => $state) {
            $r->markChannelPart($channel, \hash('sha256', self::CHATS[$channel] . ':' . $index), ['hash' => \hash('sha256', $parts[$index]), 'state' => $state]);
        }
        $em->persist($r);
        $em->flush();

        $out = $this->f1Service(new \Symfony\Component\Messenger\MessageBus())->resendResult((int) $r->id, $channel);

        self::assertSame([], $this->api->texts($channel), 'resend must not guess whether an interrupted provider call succeeded');
        self::assertSame('failed', $out['outcomes'][$channel]['state']);
        self::assertSame('sending', $r->channelParts($channel)[\hash('sha256', self::CHATS[$channel] . ':1')]['state']);
        self::assertSame('operator-uncertain', $r->channelJob($channel));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aDefiniteRefusalStillRetriesWhileAnotherChatWaitsForTheOperator(string $channel): void
    {
        $chat = self::CHATS[$channel];
        $uncertainChat = '-1009999999998';
        $refusedChat = '-1009999999999';
        $this->setting('telegram' === $channel ? 'tgRoutes' : 'maxRoutes', ['*' => $chat . ', ' . $uncertainChat . ', ' . $refusedChat]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'mixed recipient outcomes']);
        $text = $this->notifier($channel)->buildText($r);
        $r->markChannelJob($channel, 'mixed-outcomes', 'retry');
        $r->markChannelResend($channel, false);
        $r->markChannelPart($channel, \hash('sha256', $chat . ':0'), ['hash' => \hash('sha256', $text), 'state' => 'sent']);
        $r->markChannelPart($channel, \hash('sha256', $uncertainChat . ':0'), ['hash' => \hash('sha256', $text), 'state' => 'sending']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class));
        $this->api->refuseAttempt = 1;

        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false, 'mixed-outcomes'), 'only the definite refusal requests an automatic retry');
        self::assertSame('retry', $r->channelJobPhase($channel));
        self::assertSame([], $this->api->texts($channel));

        $this->api->forget();
        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, 'mixed-outcomes'));
        self::assertSame([$text], $this->api->texts($channel, $refusedChat));
        self::assertSame([], $this->api->texts($channel, $chat));
        self::assertSame([], $this->api->texts($channel, $uncertainChat));
        self::assertSame('uncertain', $r->channelJobPhase($channel), 'the lost response remains for an operator after definite failures are resolved');
    }

    #[Test]
    #[DataProvider('verifiedMessengerOutcomes')]
    public function onlyAnOperatorResolutionReleasesAnInterruptedRecipient(string $channel, bool $accepted, bool $staleProcessing): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => LongText::order(12000)]);
        $parts = $this->notifier($channel)->parts($r)->texts;
        $r->markChannel($channel, $staleProcessing ? Request::DELIVERY_PENDING : Request::DELIVERY_FAILED, $staleProcessing ? null : 'lost response');
        $r->markChannelJob($channel, 'interrupted-operation', $staleProcessing ? 'processing' : 'uncertain');
        $r->markChannelResend($channel, false);
        $r->markChannelTexts($channel, $parts);
        foreach ([0 => 'sent', 1 => 'sending'] as $index => $state) {
            $r->markChannelPart($channel, \hash('sha256', self::CHATS[$channel] . ':' . $index), ['hash' => \hash('sha256', $parts[$index]), 'state' => $state]);
        }
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $command = new \Itk\Cms\Command\ResolveRequestDeliveryCommand($deliverer);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $input = new \Symfony\Component\Console\Input\StringInput($r->id . ' ' . $channel . ' -- ' . self::CHATS[$channel] . ' ' . ($accepted ? 'sent' : 'rejected'));
        self::assertSame(0, $command->run($input, new \Symfony\Component\Console\Output\BufferedOutput()));
        self::assertSame([], $this->api->texts($channel), 'resolving records judgment and never sends');
        $em->refresh($r);
        self::assertNotSame('interrupted-operation', $r->channelJob($channel));
        self::assertSame('failed', $r->channelState($channel));
        self::assertNotEmpty($r->channelError($channel));
        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, 'interrupted-operation'));
        self::assertFalse($deliverer->resumeJob(new \Itk\Cms\Request\SendRequestNotification((int) $r->id, false, $channel, 'interrupted-operation')));
        self::assertSame([], $this->api->texts($channel), 'stale envelopes cannot resume the resolved operation');
        self::assertSame(1, $tester->execute(['request' => $r->id, 'channel' => $channel, 'recipient' => self::CHATS[$channel], 'outcome' => 'rejected']), 'a second judgment cannot clear a confirmed part');

        $r->data = ['items' => 'edited after provider verification'];
        $em->flush();
        $bus = new \Symfony\Component\Messenger\MessageBus();
        $resend = new \Itk\Cms\Command\ResendRequestsCommand($this->f1Service($bus), $c->get(\Itk\Cms\Repository\RequestRepository::class), $c->get(\Itk\Cms\Mail\RequestMailCheckpoint::class), $c->get(\Itk\Cms\Mail\MailComposer::class), new \Itk\Cms\Request\RequestQueue($em, $bus, new NullLogger()));
        $resendTester = new \Symfony\Component\Console\Tester\CommandTester($resend);
        self::assertSame(0, $resendTester->execute(['request' => $r->id, '--channel' => $channel]));

        self::assertSame(\array_slice($parts, $accepted ? 2 : 1), $this->api->texts($channel), 'remaining parts retain order and every accepted part is skipped');
        self::assertSame('sent', $r->channelState($channel));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function verifiedMessengerOutcomes(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            foreach ([false, true] as $staleProcessing) {
                yield $channel . 'accepted' . (int) $staleProcessing => [$channel, true, $staleProcessing];
                yield $channel . 'rejected' . (int) $staleProcessing => [$channel, false, $staleProcessing];
            }
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function aPartialRetryKeepsRenderedTextsAfterDataTemplateAndLocaleChange(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => LongText::order(12000)], 'long_plain');
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $original = $c->get(AdminLocale::class)->runDefault(fn (): array => $this->notifier($channel)->parts($r)->texts);
        $this->api->refuseAttempt = 2;
        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false));
        $accepted = $this->api->texts($channel);
        $job = (string) $r->channelJob($channel);
        $deliverer->queueFailed((int) $r->id, $channel, $job, 'exhausted');
        $r->data = ['items' => 'changed while delivery was interrupted'];
        $em->flush();
        $c->get(Schema::class)->form('long_plain')->telegram('Changed title and template {items}');
        $english = new AdminLocale('en', ['ru', 'en'], 'ru', new NullLogger(), $c->get('translator'), $c->get('translation.locale_switcher'));
        $retry = new RequestDeliverer($em, $this->telegram, $this->max, $english);
        self::assertNotSame($original, $english->runDefault(fn (): array => $this->notifier($channel)->parts($r)->texts));
        $this->api->forget();

        $retry->deliverSynchronously((int) $r->id, $channel, true);

        self::assertSame($original, [...$accepted, ...$this->api->texts($channel)]);
        self::assertSame('done', $r->channelJobPhase($channel));
        self::assertSame($job, $r->channelJob($channel));
    }

    #[Test]
    public function aConfiguredIncompatibleTelegramClientCreatesAFailedQueuedOperation(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $wire = new \Symfony\Component\HttpClient\MockHttpClient(new \Symfony\Component\HttpClient\Response\MockResponse('{"ok":true,"result":{"message_id":1}}'));
        $typed = new class(new \Symfony\Component\HttpClient\RetryableHttpClient($wire)) extends \Symfony\Component\HttpClient\MockHttpClient {
            public function __construct(private \Symfony\Component\HttpClient\RetryableHttpClient $decorated)
            {
                parent::__construct();
            }

            public function __destruct()
            {
                unset($this->decorated);
            }
        };
        $connection = new \Itk\Cms\Request\TelegramConnection(static fn (): TransportInterface => new \Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport('123456:private-token', client: $typed), new NullLogger());
        $this->telegram = new TelegramNotifier($c->get(Schema::class), $c->get(SettingsService::class), new NullLogger(), $connection, $c->get('event_dispatcher'), true);
        self::assertTrue($this->telegram->isActive());
        $r = self::request(['items' => 'configured transport failure']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $handlers = new \Symfony\Component\Messenger\Handler\HandlersLocator([\Itk\Cms\Request\SendRequestNotification::class => [new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer)]]);
        $bus = new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware($handlers)]);
        (new \Itk\Cms\Request\RequestQueue($em, $bus, new NullLogger()))->enqueue((int) $r->id, 'telegram');
        $em->refresh($r);
        self::assertNotNull($r->channelJob('telegram'));
        self::assertSame('failed', $r->channelJobPhase('telegram'));
        self::assertSame(Request::DELIVERY_FAILED, $r->channelState('telegram'));
        self::assertSame([], $r->channelParts('telegram'), 'guard failed before provider I/O, so no ambiguous checkpoint remains');
        self::assertStringContainsString('decorated', (string) $r->channelError('telegram'));
        self::assertStringNotContainsString('private-token', (string) $r->channelError('telegram'));
        self::assertSame(0, $wire->getRequestsCount());
    }

    #[Test]
    #[DataProvider('channels')]
    public function legacyOtherChannelOverwriteRetainsFrozenTextsAndEveryAcceptedPart(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => LongText::order(12000)]);
        $em->persist($r);
        $em->flush();
        $original = $this->notifier($channel)->parts($r)->texts;
        $job = RequestDeliverer::reserve($em, (int) $r->id, $channel, false);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $api = $this->api;
        $api->refuseAttempt = 2;
        $api->beforeAttempt = static function () use ($api, $em, $r, $channel): void {
            if (1 !== $api->attempts) {
                return;
            }
            $api->beforeAttempt = null;
            $other = DriverManager::getConnection($em->getConnection()->getParams());
            try {
                $otherChannel = 'telegram' === $channel ? 'max' : 'telegram';
                $other->executeStatement('UPDATE itk_request SET channels = ?, delivery = ? WHERE id = ?', [\json_encode([$otherChannel => ['state' => 'sent', 'error' => null]], \JSON_THROW_ON_ERROR), 'sent', $r->id]);
            } finally {
                $other->close();
            }
        };
        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false, $job));
        self::assertSame($original, $r->channelTexts($channel));
        self::assertFalse($r->channelResend($channel));
        self::assertCount(1, $r->channelParts($channel));
        self::assertSame('sent', $r->channelState('telegram' === $channel ? 'max' : 'telegram'));
        $accepted = $api->texts($channel);
        $r->data = ['items' => 'edited after a legacy worker replaced the channel JSON'];
        $em->flush();
        $api->forget();

        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, $job));

        self::assertSame($original, [...$accepted, ...$api->texts($channel)]);
        self::assertSame(\array_slice($original, 1), $api->texts($channel));
    }

    #[Test]
    #[DataProvider('channels')]
    public function aDistinctSendAfterCompletionFormatsANewSnapshot(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'first operation']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false));
        $firstJob = $r->channelJob($channel);
        $r->data = ['items' => 'second operation after completion'];
        $em->flush();
        $this->api->forget();
        self::assertTrue($deliverer->deliver((int) $r->id, $channel, true));
        self::assertNotSame($firstJob, $r->channelJob($channel));
        self::assertSame($c->get(AdminLocale::class)->runDefault(fn (): array => $this->notifier($channel)->parts($r, true)->texts), $this->api->texts($channel));
    }

    #[Test]
    #[DataProvider('channels')]
    public function anExplicitResendAfterEveryPartWasRejectedFormatsANewOperation(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'original text']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $original = $this->notifier($channel)->parts($r)->texts;
        $this->api->refuseAttempt = 1;
        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false));
        $job = (string) $r->channelJob($channel);
        $deliverer->queueFailed((int) $r->id, $channel, $job, 'exhausted');
        $r->data = ['items' => 'edited request'];
        $em->flush();
        $c->get(Schema::class)->form('long_text')->telegram('Updated template: {items}');
        $this->api->forget();

        $this->f1Service(new \Symfony\Component\Messenger\MessageBus())->resendResult((int) $r->id, $channel);

        self::assertNotSame($original, $this->api->texts($channel));
        self::assertSame($c->get(AdminLocale::class)->runDefault(fn (): array => $this->notifier($channel)->parts($r, true)->texts), $this->api->texts($channel));
        self::assertNotSame($job, $r->channelJob($channel));
    }

    #[Test]
    #[DataProvider('channels')]
    public function automaticRetryAfterEveryPartWasRejectedKeepsItsFrozenSnapshot(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'original automatic operation']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $this->api->refuseAttempt = 1;
        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false));
        $job = (string) $r->channelJob($channel);
        $original = $r->channelTexts($channel);
        $r->data = ['items' => 'edited after a definite rejection'];
        $em->flush();
        $this->api->forget();

        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, $job));

        self::assertSame($original, $this->api->texts($channel));
        self::assertSame($job, $r->channelJob($channel));
    }

    #[Test]
    #[DataProvider('disabledPartialRetries')]
    public function disablingAnUnfinishedDeliveryPreservesItsAcceptedPartsAndSnapshot(string $channel, bool $manual, bool $twoRecipients): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $firstChat = self::CHATS[$channel];
        $secondChat = '-1009999999999';
        if ($twoRecipients) {
            $this->setting('telegram' === $channel ? 'tgRoutes' : 'maxRoutes', ['*' => $firstChat . ', ' . $secondChat]);
        }
        $r = self::request(['items' => $twoRecipients ? 'same message, two recipients' : LongText::order(12000)]);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $this->api->refuseAttempt = 2;
        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false));
        $job = (string) $r->channelJob($channel);
        $texts = $r->channelTexts($channel);
        $parts = $r->channelParts($channel);
        $accepted = $this->api->texts($channel);
        $enabled = 'telegram' === $channel ? 'tgOn' : 'maxOn';
        $this->setting($enabled, false);
        $this->api->forget();

        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false, $job));
        self::assertSame('retry', $r->channelJobPhase($channel));
        self::assertSame('failed', $r->channelState($channel));
        self::assertSame($parts, $r->channelParts($channel));
        self::assertSame($texts, $r->channelTexts($channel));
        self::assertSame([], $this->api->texts($channel));
        $r->data = ['items' => 'edited while the messenger was disabled'];
        $em->flush();
        $this->setting($enabled, true);
        if ($manual) {
            $deliverer->queueFailed((int) $r->id, $channel, $job, 'exhausted while disabled');
            $this->f1Service(new \Symfony\Component\Messenger\MessageBus())->resendResult((int) $r->id, $channel);
        } else {
            self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, $job));
        }

        if ($twoRecipients) {
            self::assertSame($texts, $accepted);
            self::assertSame([], $this->api->texts($channel, $firstChat), 'Chat A already accepted the message');
            self::assertSame($texts, $this->api->texts($channel, $secondChat), 'Only chat B receives the frozen text');
        } else {
            self::assertSame($texts, [...$accepted, ...$this->api->texts($channel)]);
            self::assertSame(\array_slice($texts, 1), $this->api->texts($channel));
        }
        self::assertSame($job, $r->channelJob($channel));
        self::assertSame('done', $r->channelJobPhase($channel));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function disabledPartialRetries(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            yield $channel . ' automatic parts' => [$channel, false, false];
            yield $channel . ' manual parts' => [$channel, true, false];
            yield $channel . ' automatic recipients' => [$channel, false, true];
            yield $channel . ' manual recipients' => [$channel, true, true];
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function anInterruptedOperationKeepsItsSavedTextWhileTheChannelIsDisabled(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'saved before a worker interruption']);
        $job = 'paused-frozen-operation';
        $texts = $c->get(AdminLocale::class)->runDefault(fn (): array => $this->notifier($channel)->parts($r)->texts);
        $r->markChannelJob($channel, $job, 'retry');
        $r->markChannelTexts($channel, $texts);
        $em->persist($r);
        $em->flush();
        $enabled = 'telegram' === $channel ? 'tgOn' : 'maxOn';
        $this->setting($enabled, false);
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));

        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false, $job));
        self::assertSame('retry', $r->channelJobPhase($channel));
        self::assertSame([], $this->api->texts($channel));
        $r->data = ['items' => 'edited after a worker interruption'];
        $em->flush();
        $this->setting($enabled, true);

        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, $job));
        self::assertSame($texts, $this->api->texts($channel));
        self::assertSame($job, $r->channelJob($channel));
    }

    #[Test]
    #[DataProvider('unresolvedPhases')]
    public function resendNeverDeletesAnUnresolvedPart(string $channel, string $phase): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'unknown acceptance']);
        $text = $this->notifier($channel)->buildText($r);
        $r->markChannel($channel, Request::DELIVERY_FAILED, 'lost response');
        $r->markChannelJob($channel, 'unresolved', $phase, 0);
        $r->markChannelResend($channel, false);
        $key = \hash('sha256', self::CHATS[$channel] . ':0');
        $r->markChannelPart($channel, $key, ['hash' => \hash('sha256', $text), 'state' => 'sending']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $deliverer->deliverSynchronously((int) $r->id, $channel, true);
        $em->refresh($r);

        self::assertSame([], $this->api->texts($channel));
        self::assertSame('sending', $r->channelParts($channel)[$key]['state']);
        self::assertSame('unresolved', $r->channelJob($channel));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unresolvedPhases(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            foreach (['uncertain', 'failed', 'processing', 'retry'] as $phase) {
                yield $channel . $phase => [$channel, $phase];
            }
        }
    }

    #[Test]
    #[DataProvider('temporarilyHiddenUnknownRecipients')]
    public function temporarilyHiddenUnknownRecipientsNeverBecomeAFreshOperation(string $channel, bool $disabled): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $unknown = self::CHATS[$channel];
        $r = self::request(['items' => 'an unknown recipient is temporarily hidden']);
        $texts = $this->notifier($channel)->parts($r)->texts;
        $r->markChannel($channel, Request::DELIVERY_FAILED, 'response lost');
        $r->markChannelJob($channel, 'hidden-unknown', 'retry', 0);
        $r->markChannelResend($channel, false);
        $r->markChannelTexts($channel, $texts);
        $key = \hash('sha256', $unknown . ':0');
        $r->markChannelPart($channel, $key, ['hash' => \hash('sha256', $texts[0]), 'state' => 'sending']);
        $em->persist($r);
        $em->flush();
        $routes = 'telegram' === $channel ? 'tgRoutes' : 'maxRoutes';
        $enabled = 'telegram' === $channel ? 'tgOn' : 'maxOn';
        if ($disabled) {
            $this->setting($enabled, false);
        } else {
            $this->setting($routes, ['*' => '-1009999999999']);
        }
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        self::assertTrue($deliverer->deliver((int) $r->id, $channel, false, 'hidden-unknown'));
        $em->refresh($r);
        self::assertSame('uncertain', $r->channelJobPhase($channel));
        self::assertSame('failed', $r->channelState($channel));
        $this->setting($enabled, true);
        $this->setting($routes, ['*' => $unknown]);
        $this->api->forget();
        $deliverer->deliverSynchronously((int) $r->id, $channel, true);
        $em->refresh($r);
        self::assertSame([], $this->api->texts($channel));
        self::assertSame('sending', $r->channelParts($channel)[$key]['state']);
        self::assertSame('hidden-unknown', $r->channelJob($channel));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function temporarilyHiddenUnknownRecipients(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            yield $channel . 'disabled' => [$channel, true];
            yield $channel . 'route removed' => [$channel, false];
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function legacyDoneWithAnUnresolvedPartIsQuarantinedBeforeNewReservation(string $channel): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'a previous worker incorrectly marked this operation done']);
        $texts = $this->notifier($channel)->parts($r)->texts;
        $r->markChannel($channel, Request::DELIVERY_SENT);
        $r->markChannelJob($channel, 'legacy-done-unknown', 'done');
        $r->markChannelTexts($channel, $texts);
        $key = \hash('sha256', self::CHATS[$channel] . ':0');
        $r->markChannelPart($channel, $key, ['hash' => \hash('sha256', $texts[0]), 'state' => 'sending']);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $deliverer->deliverSynchronously((int) $r->id, $channel, true);
        $em->refresh($r);
        self::assertSame([], $this->api->texts($channel));
        self::assertSame('sending', $r->channelParts($channel)[$key]['state']);
        self::assertSame('uncertain', $r->channelJobPhase($channel));
        self::assertSame('failed', $r->channelState($channel));
        self::assertSame('legacy-done-unknown', $r->channelJob($channel));
    }

    #[Test]
    #[DataProvider('mixedManualRetries')]
    public function everyManualRetryPreservesAnUnknownRecipientWhileContinuingDefiniteFailures(string $channel, string $phase, string $route): void
    {
        $chat = self::CHATS[$channel];
        $unknown = '-1009999999998';
        $refused = '-1009999999999';
        $this->setting('telegram' === $channel ? 'tgRoutes' : 'maxRoutes', ['*' => $chat . ', ' . $unknown . ', ' . $refused]);
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'mixed recipients after exhaustion or an expired worker lease']);
        $texts = $this->notifier($channel)->parts($r)->texts;
        $r->markChannel($channel, Request::DELIVERY_FAILED, 'response lost and another recipient refused');
        $r->markChannelJob($channel, 'mixed-manual-operation', $phase, 0);
        $r->markChannelResend($channel, false);
        $r->markChannelTexts($channel, $texts);
        foreach ([$chat => 'sent', $unknown => 'sending'] as $recipient => $state) {
            $r->markChannelPart($channel, \hash('sha256', $recipient . ':0'), ['hash' => \hash('sha256', $texts[0]), 'state' => $state]);
        }
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, $c->get(AdminLocale::class));
        $handlers = new \Symfony\Component\Messenger\Handler\HandlersLocator([\Itk\Cms\Request\SendRequestNotification::class => [new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer)]]);
        $bus = new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware($handlers)]);
        $service = $this->f1Service($bus);
        if ('bulk' === $route) {
            $service->requeueFailedResult();
        } else {
            $service->resendResult((int) $r->id, 'targeted' === $route ? $channel : null);
        }
        $em->refresh($r);
        self::assertSame([], $this->api->texts($channel, $chat));
        self::assertSame([], $this->api->texts($channel, $unknown));
        self::assertSame($texts, $this->api->texts($channel, $refused));
        self::assertSame('sending', $r->channelParts($channel)[\hash('sha256', $unknown . ':0')]['state']);
        self::assertSame('uncertain', $r->channelJobPhase($channel));
        self::assertSame('mixed-manual-operation', $r->channelJob($channel));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function mixedManualRetries(): iterable
    {
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            foreach (['failed', 'processing'] as $phase) {
                foreach (['targeted', 'all', 'bulk'] as $route) {
                    yield $channel . $phase . $route => [$channel, $phase, $route];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function bulkManualRetryDoesNotRepeatConfirmedParts(string $channel): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => LongText::order(12000)]);
        $em->persist($r);
        $em->flush();
        $deliverer = new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class));
        $this->api->refuseAttempt = 2;
        self::assertFalse($deliverer->deliver((int) $r->id, $channel, false));
        $first = $this->api->texts($channel);
        $deliverer->queueFailed((int) $r->id, $channel, (string) $r->channelJob($channel), 'terminal refusal');
        $this->api->forget();
        $handlers = new \Symfony\Component\Messenger\Handler\HandlersLocator([\Itk\Cms\Request\SendRequestNotification::class => [new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer)]]);
        $bus = new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware($handlers)]);

        $this->f1Service($bus)->requeueFailed();

        self::assertSame($this->notifier($channel)->buildText($r), \implode('', [...$first, ...$this->api->texts($channel)]));
        self::assertSame('done', $r->channelJobPhase($channel));
    }

    #[Test]
    #[DataProvider('bulkRoutingOutcomes')]
    public function bulkResendCommandReportsSynchronousOutcomesInsteadOfQueueAcceptance(?bool $refused): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'bulk synchronous refusal']);
        $r->markChannel('telegram', Request::DELIVERY_FAILED, 'previous refusal');
        $em->persist($r);
        $em->flush();
        $this->api->refuseAttempt = $refused ? 1 : 0;
        $handlers = new \Symfony\Component\Messenger\Handler\HandlersLocator([\Itk\Cms\Request\SendRequestNotification::class => [new \Itk\Cms\Request\SendRequestNotificationHandler(new RequestDeliverer($em, $this->telegram, $this->max, self::getContainer()->get(AdminLocale::class)))]]);
        $sync = new \Symfony\Component\Messenger\Transport\Sync\SyncTransport(new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware($handlers)]));
        $send = new class($sync) implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {
            public function __construct(private readonly \Symfony\Component\Messenger\Transport\Sync\SyncTransport $transport)
            {
            }

            public function handle(\Symfony\Component\Messenger\Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): \Symfony\Component\Messenger\Envelope
            {
                return $this->transport->send($envelope);
            }
        };
        $bus = new \Symfony\Component\Messenger\MessageBus(null === $refused ? [] : [$send]);
        $container = self::getContainer();
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Itk\Cms\Command\ResendRequestsCommand(
            $this->f1Service($bus),
            $container->get(\Itk\Cms\Repository\RequestRepository::class),
            $container->get(\Itk\Cms\Mail\RequestMailCheckpoint::class),
            $container->get(\Itk\Cms\Mail\MailComposer::class),
            $container->get(\Itk\Cms\Request\RequestQueue::class),
        ));

        $tester->execute([]);

        self::assertSame(null === $refused ? 'queued' : ($refused ? 'failed' : 'done'), $r->channelJobPhase('telegram'));
        self::assertSame(null === $refused ? ['telegram'] : [], $r->queuedChannels());
        self::assertStringContainsString('В очередь поставлено заявок: ' . (null === $refused ? '1' : '0') . '.', $tester->getDisplay());
        if (null === $refused) {
            self::assertStringContainsString('messenger:consume async', $tester->getDisplay());
        } else {
            self::assertStringContainsString($refused ? 'ошибок доставки: 1' : 'доставлено: 1', $tester->getDisplay());
            self::assertStringNotContainsString('messenger:consume async', $tester->getDisplay(), 'there is nothing waiting for a worker');
        }
    }

    #[Test]
    public function bulkResendReportsSkippedUncertainChannelsSeparately(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $r = self::request(['items' => 'both messenger outcomes require operator verification']);
        foreach (\array_keys(Request::CHANNELS) as $channel) {
            $r->markChannel($channel, Request::DELIVERY_FAILED, 'response lost');
            $r->markChannelJob($channel, 'waiting-' . $channel, 'uncertain');
        }
        $em->persist($r);
        $em->flush();
        $service = $this->f1Service(new \Symfony\Component\Messenger\MessageBus());
        self::assertSame(['queued' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'uncertain' => 2], $service->requeueFailedResult());
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Itk\Cms\Command\ResendRequestsCommand(
            $service,
            $c->get(\Itk\Cms\Repository\RequestRepository::class),
            $c->get(\Itk\Cms\Mail\RequestMailCheckpoint::class),
            $c->get(\Itk\Cms\Mail\MailComposer::class),
            $c->get(\Itk\Cms\Request\RequestQueue::class),
        ));

        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('ждут решения оператора: 2 (itk:requests:resolve)', $tester->getDisplay());
        self::assertStringNotContainsString('Завершённые отправки', $tester->getDisplay());
        self::assertStringNotContainsString('messenger:consume', $tester->getDisplay());
        self::assertSame([], $this->api->texts('telegram'));
        self::assertSame([], $this->api->texts('max'));
    }

    /** @return iterable<string, array{?bool}> */
    public static function bulkRoutingOutcomes(): iterable
    {
        yield 'synchronous refusal' => [true];
        yield 'synchronous success' => [false];
        yield 'asynchronous waiting' => [null];
    }

    private function dispatchDelivery(RequestDeliverer $deliverer, \Itk\Cms\Request\SendRequestNotification $message): void
    {
        $bus = new \Symfony\Component\Messenger\MessageBus([new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(
            new \Symfony\Component\Messenger\Handler\HandlersLocator([$message::class => [new \Itk\Cms\Request\SendRequestNotificationHandler($deliverer)]]),
        )]);
        $bus->dispatch($message);
    }

    private function f1Service(\Symfony\Component\Messenger\MessageBusInterface $bus): \Itk\Cms\Request\RequestService
    {
        $c = self::getContainer();

        return new \Itk\Cms\Request\RequestService(
            $c->get(EntityManagerInterface::class),
            $c->get(\Itk\Cms\Repository\RequestRepository::class),
            $c->get(Schema::class),
            $c->get(SettingsService::class),
            $this->telegram,
            $this->max,
            new RequestDeliverer($c->get(EntityManagerInterface::class), $this->telegram, $this->max, $c->get(AdminLocale::class)),
            $c->get(\Itk\Cms\Notification\NotifierInterface::class),
            $bus,
            'f1-secret',
            'ru',
        );
    }

    #[Test]
    public function f1AmbiguousQueueDispatchRetainsItsDurableJob(): void
    {
        $r = self::request(['items' => \str_repeat('x', 85000)], 'long_f1_resend');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('unavailable'));
        $result = $this->f1Service($bus)->resendResult((int) $r->id, 'telegram');
        self::assertSame(['telegram'], $result['queuedChannels']);
        self::assertSame('queued', $result['outcomes']['telegram']['state']);
        self::assertSame(202, $result['status'], 'the application has durably accepted the publication intent');
        self::assertSame(Request::DELIVERY_FAILED, $r->delivery);
        self::assertSame([], $this->api->texts('telegram'));
    }

    /** @return iterable<array-key, mixed> */
    public static function f1HttpCases(): iterable
    {
        yield '20 stays synchronous' => [20, false, 200];
        yield '21 accepted in queue' => [21, false, 202];
        yield 'publication unknown, intent durable' => [21, true, 202];
        yield 'health synchronous' => [20, false, 200, true];
        yield 'health queued' => [21, false, 202, true];
        yield 'health publication unknown, intent durable' => [21, true, 202, true];
    }

    #[Test]
    #[DataProvider('f1HttpCases')]
    public function f1HttpResendSeparatesQueueAcceptanceFromDelivery(int $parts, bool $reject, int $status, bool $health = false): void
    {
        $client = static::getClient();
        \assert($client instanceof \Symfony\Bundle\FrameworkBundle\KernelBrowser);
        $client->disableReboot();
        $c = self::getContainer();
        $r = self::request(['items' => \str_repeat('x', 4096 * ($parts - 1) - 100)], 'long_f1_resend');
        $r->markChannel('telegram', Request::DELIVERY_FAILED, 'earlier test refusal');
        self::assertCount($parts, $this->telegram->parts($r, true)->texts, 'the actual resend formatter defines the boundary');
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        if ($parts > 20) {
            $expectation = $bus->expects(self::once())->method('dispatch');
            if ($reject) {
                $expectation->willThrowException(new \RuntimeException('test queue refusal'));
            } else {
                $expectation->willReturnCallback(static fn ($message) => new \Symfony\Component\Messenger\Envelope($message));
            }
        } else {
            $bus->expects(self::never())->method('dispatch');
        }
        $c->set(\Itk\Cms\Request\RequestService::class, $this->f1Service($bus));
        $admin = $c->get(\Itk\Cms\Security\UserManager::class)->create('test-f1-resend@example.com', 'F1 resend', 'admin', 'f1-harmless-password');
        $client->loginUser($admin, 'itk_admin');
        $client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $client->getResponse()->getContent(), $match);
        $client->jsonRequest('POST', '/admin/api/' . ($health ? 'health/requests/' : 'requests/') . $r->id . '/resend', ['channel' => 'telegram'], ['HTTP_X_CSRF_TOKEN' => $match[1]]);
        self::assertSame($status, $client->getResponse()->getStatusCode());
        $out = \json_decode((string) $client->getResponse()->getContent(), true);
        if (!$reject) {
            self::assertSame($parts > 20 ? ['telegram'] : [], $out['queuedChannels']);
        } else {
            self::assertSame(['telegram'], $out['queuedChannels'], 'an ambiguous broker response retains the durably accepted job');
            self::assertSame('queued', $out['outcomes']['telegram']['state']);
            self::assertNotNull($out['outcomes']['telegram']['error']);
            self::assertStringNotContainsString('test queue refusal', $out['outcomes']['telegram']['error']);
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$r->id]));
        }
        self::assertCount($parts > 20 ? 0 : 20, $this->api->texts('telegram'));
    }

    #[Test]
    #[DataProvider('channels')]
    public function f1ResendCountsEscapedPartsOnlyInTheChosenChannel(string $channel): void
    {
        $r = self::request(['items' => \str_repeat('&', 16400)], 'long_f1_resend');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        if ('max' === $channel) {
            $bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn ($message) => new \Symfony\Component\Messenger\Envelope($message));
        } else {
            $bus->expects(self::never())->method('dispatch');
        }
        $out = $this->f1Service($bus)->resendResult((int) $r->id, $channel);
        self::assertSame('max' === $channel ? ['max'] : [], $out['queuedChannels']);
        self::assertCount('max' === $channel ? 0 : 5, $this->api->texts($channel));
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->saved as $key => [$existed, $value]) {
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', $key)->execute();
            if ($existed) {
                $settings->set($key, $value);
            }
        }
        $em->createQuery("DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.email = 'test-f1-resend@example.com'")->execute();
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

    public static function channels(): iterable
    {
        yield 'Telegram' => [Request::CHANNEL_TELEGRAM];
        yield 'MAX' => [Request::CHANNEL_MAX];
    }

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
    public function afterARefusedSecondMessageTheRequestIsFailedAndARetryStartsFromTheFirst(string $channel): void
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
        self::assertSame($first[0], $retry[0], 'the first message comes again');
        self::assertSame($this->notifier($channel)->buildText($r), \implode('', $retry));
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

    public static function f1ResendSizes(): iterable
    {
        foreach (['telegram', 'max'] as $channel) {
            foreach ([20, 21] as $parts) {
                yield $channel . $parts => [$channel, $parts];
            }
        }
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
    public function f1FailedQueueDispatchCannotReportAcceptance(): void
    {
        $r = self::request(['items' => \str_repeat('x', 85000)], 'long_f1_resend');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($r);
        $em->flush();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException(new \RuntimeException('unavailable'));
        try {
            $this->f1Service($bus)->resendResult((int) $r->id, 'telegram');
            self::fail('a rejected dispatch must not report queued');
        } catch (\Itk\Cms\Content\ContentException $e) {
            self::assertSame(502, $e->status());
            self::assertSame(Request::DELIVERY_PENDING, $r->delivery);
            self::assertSame([], $this->api->texts('telegram'));
        }
    }

    public static function f1HttpCases(): iterable
    {
        yield '20 stays synchronous' => [20, false, 200];
        yield '21 accepted in queue' => [21, false, 202];
        yield 'queue rejected' => [21, true, 502];
        yield 'health synchronous' => [20, false, 200, true];
        yield 'health queued' => [21, false, 202, true];
        yield 'health queue rejected' => [21, true, 502, true];
    }

    #[Test]
    #[DataProvider('f1HttpCases')]
    public function f1HttpResendSeparatesQueueAcceptanceFromDelivery(int $parts, bool $reject, int $status, bool $health = false): void
    {
        $client = static::getClient();
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

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\Health\UpdateChecker;
use Itk\Cms\Request\SendRequestNotification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/** «Состояние сайта», логика без HTTP: очередь и упавшие сообщения (повторить, удалить), проверка обновлений с кешем на 12 часов. */
final class HealthMonitorTest extends TestCase
{
    private function queue(int $waiting): InMemoryTransport&MessageCountAwareInterface
    {
        return new class($waiting) extends InMemoryTransport implements MessageCountAwareInterface {
            public function __construct(private readonly int $waiting)
            {
                parent::__construct();
            }

            public function getMessageCount(): int
            {
                return $this->waiting;
            }
        };
    }

    private function failedEnvelope(string $id, string $original = 'async', string $error = 'boom'): Envelope
    {
        return new Envelope(new SendRequestNotification(736), [
            new TransportMessageIdStamp($id),
            new SentToFailureTransportStamp($original),
            new ErrorDetailsStamp(\RuntimeException::class, 0, $error),
            new RedeliveryStamp(5),
            new DelayStamp(1000),
            new BusNameStamp('messenger.bus.default'),
        ]);
    }

    /**
     * @param array<string, object> $transports by name; each is also known by its service id, as in the real locator
     *
     * @return ServiceLocator<object>
     */
    private function locator(array $transports): ServiceLocator
    {
        $factories = [];
        foreach ($transports as $name => $t) {
            $factories[$name] = static fn () => $t;
            $factories['messenger.transport.' . $name] = static fn () => $t;
        }

        return new ServiceLocator($factories);
    }

    #[Test]
    public function queuesListOnlyTransportsThatCountAndSkipTheFailureOne(): void
    {
        $failed = new FailedHealthMonitorTransport($this->failedEnvelope('7'));
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(3), 'failed' => $failed, 'sync' => new InMemoryTransport()]), $failed);

        self::assertSame([['name' => 'async', 'waiting' => 3]], $monitor->queues());
    }

    #[Test]
    public function failedMessagesComeWithAReasonAndNoBotToken(): void
    {
        $failed = new FailedHealthMonitorTransport($this->failedEnvelope('7', 'async', 'Client error for https://api.telegram.org/bot123456:AAH-secret_token/sendMessage'));
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), $failed);

        $report = $monitor->failed();
        self::assertTrue($report['available']);
        self::assertSame(1, $report['total']);
        $item = $report['items'][0];
        self::assertSame('7', $item['id']);
        self::assertSame('Отправка заявки № 736 в Telegram', $item['label']);
        self::assertSame(SendRequestNotification::class, $item['type']);
        self::assertSame(\RuntimeException::class, $item['errorClass']);
        self::assertSame(5, $item['retries']);
        self::assertSame('async', $item['queue']);
        self::assertStringContainsString('bot***', $item['error']);
        self::assertStringNotContainsString('AAH-secret_token', $item['error']);
    }

    #[Test]
    public function aFailedMessageQueuedBeforeChannelsExistedIsLabelledWithTelegram(): void
    {
        $old = \unserialize('O:39:"Itk\\Cms\\Request\\SendRequestNotification":2:{s:9:"requestId";i:736;s:6:"resend";b:1;}');
        $envelope = new Envelope($old, [new TransportMessageIdStamp('9'), new SentToFailureTransportStamp('async'), new ErrorDetailsStamp(\RuntimeException::class, 0, 'x'), new RedeliveryStamp(5)]);
        $failed = new FailedHealthMonitorTransport($envelope);
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), $failed);

        self::assertSame('Отправка заявки № 736 в Telegram', $monitor->failed()['items'][0]['label']);
    }

    #[Test]
    public function aFailedMaxMessageIsLabelledWithMax(): void
    {
        $envelope = new Envelope(new SendRequestNotification(736, true, 'max'), [new TransportMessageIdStamp('8'), new SentToFailureTransportStamp('async'), new ErrorDetailsStamp(\RuntimeException::class, 0, 'no access'), new RedeliveryStamp(5)]);
        $failed = new FailedHealthMonitorTransport($envelope);
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), $failed);

        self::assertSame('Отправка заявки № 736 в MAX', $monitor->failed()['items'][0]['label']);
    }

    #[Test]
    public function aLongErrorIsCut(): void
    {
        self::assertLessThanOrEqual(401, \mb_strlen(MessengerMonitor::scrub(\str_repeat('я', 2000))));
    }

    #[Test]
    public function withoutAFailureTransportTheFailedBlockIsEmpty(): void
    {
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0)]), null);

        self::assertSame(['available' => false, 'total' => 0, 'items' => []], $monitor->failed());
        $this->expectException(ContentException::class);
        $monitor->delete('1');
    }

    #[Test]
    public function retryPutsTheMessageBackIntoItsQueueAsANewOne(): void
    {
        $failed = new FailedHealthMonitorTransport($this->failedEnvelope('7'), $this->failedEnvelope('8'));
        $async = $this->queue(0);
        $monitor = new MessengerMonitor($this->locator(['async' => $async, 'failed' => $failed]), $failed);

        $monitor->retry('7');

        $sent = $async->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new SendRequestNotification(736), $sent[0]->getMessage());
        self::assertNull($sent[0]->last(RedeliveryStamp::class), 'the retry count starts over');
        self::assertNull($sent[0]->last(SentToFailureTransportStamp::class));
        self::assertNull($sent[0]->last(ErrorDetailsStamp::class));
        self::assertSame('messenger.bus.default', $sent[0]->last(BusNameStamp::class)?->getBusName(), 'the bus is kept');
        self::assertSame(['7'], $failed->acked, 'it leaves the failure transport, the other message stays');
        self::assertSame([], $failed->rejected);
    }

    #[Test]
    public function retryRefusesWhenTheOriginalQueueIsGone(): void
    {
        $failed = new FailedHealthMonitorTransport($this->failedEnvelope('7', 'vanished'));
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), $failed);

        try {
            $monitor->retry('7');
            self::fail('expected a refusal');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
        self::assertSame([], $failed->acked, 'nothing is lost');
    }

    #[Test]
    public function legacyPasswordMessagesCannotBeRetried(): void
    {
        foreach ([new \Itk\Cms\Mail\SendMail('fixture@example.com', 'Legacy', 'Fixture', '<p>Fixture</p>', 'reset'), new \Itk\Cms\Security\RequestPasswordLink('fixture@example.com', 'https://old.example')] as $job) {
            $envelope = new Envelope($job, [new TransportMessageIdStamp('legacy'), new SentToFailureTransportStamp('async')]);
            $failed = new FailedHealthMonitorTransport($envelope);
            $async = $this->queue(0);
            $monitor = new MessengerMonitor($this->locator(['async' => $async, 'failed' => $failed]), $failed);
            try {
                $monitor->retry('legacy');
                self::fail('Legacy password jobs must be deleted, never put back into the queue.');
            } catch (ContentException $error) {
                self::assertSame(422, $error->status());
            }
            self::assertCount(0, $async->getSent());
            self::assertSame([], $failed->acked);
        }
    }

    #[Test]
    public function deleteDropsOneMessage(): void
    {
        $failed = new FailedHealthMonitorTransport($this->failedEnvelope('7'), $this->failedEnvelope('8'));
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), $failed);

        $monitor->delete('8');

        self::assertSame(['8'], $failed->rejected);
        self::assertSame([], $failed->acked);
    }

    #[Test]
    public function anUnknownMessageIsNotFound(): void
    {
        $failed = new FailedHealthMonitorTransport();
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), $failed);

        foreach (['retry', 'delete'] as $do) {
            try {
                $monitor->$do('999');
                self::fail('expected 404');
            } catch (ContentException $e) {
                self::assertSame(404, $e->status());
            }
        }
    }

    /**
     * @param array<string, \Closure(): object> $factories by name; each is also known by its service id
     *
     * @return ServiceLocator<object>
     */
    private function lazyLocator(array $factories): ServiceLocator
    {
        $all = [];
        foreach ($factories as $name => $f) {
            $all[$name] = $f;
            $all['messenger.transport.' . $name] = $f;
        }

        return new ServiceLocator($all);
    }

    #[Test]
    public function aTransportThatCannotBeBuiltIsShownWithItsReasonInsteadOfFailingTheScreen(): void
    {
        $good = $this->queue(2);
        $monitor = new MessengerMonitor($this->lazyLocator([
            'async' => static fn () => throw new \InvalidArgumentException('No transport supports Messenger DSN "doctrine://default".'),
            'fast' => static fn () => $good,
        ]), null);

        $queues = $monitor->queues();

        self::assertCount(2, $queues);
        self::assertSame(['name' => 'fast', 'waiting' => 2], $queues[1]);
        self::assertSame('async', $queues[0]['name']);
        self::assertNull($queues[0]['waiting']);
        self::assertStringContainsString('doctrine://default', $queues[0]['error']);
    }

    #[Test]
    public function aFailureTransportThatCannotBeBuiltIsReportedAndTheQueuesStillWork(): void
    {
        $monitor = new MessengerMonitor(
            $this->lazyLocator(['async' => fn () => $this->queue(3)]),
            static fn () => throw new \RuntimeException('failure transport broke at https://api.telegram.org/bot123456:AAH-secret_token/x'),
        );

        self::assertSame([['name' => 'async', 'waiting' => 3]], $monitor->queues());
        $failed = $monitor->failed();
        self::assertTrue($failed['available']);
        self::assertSame(0, $failed['total']);
        self::assertStringContainsString('failure transport broke', $failed['error']);
        self::assertStringNotContainsString('AAH-secret_token', $failed['error']);

        try {
            $monitor->retry('1');
            self::fail('expected 404');
        } catch (ContentException $e) {
            self::assertSame(404, $e->status());
        }
    }

    #[Test]
    public function theFailureTransportIsBuiltOnlyWhenNeededAndOnlyOnce(): void
    {
        $built = 0;
        $failed = new FailedHealthMonitorTransport($this->failedEnvelope('7'));
        $monitor = new MessengerMonitor($this->locator(['async' => $this->queue(0), 'failed' => $failed]), static function () use (&$built, $failed) {
            ++$built;

            return $failed;
        });

        self::assertSame(0, $built);
        $monitor->queues();
        $monitor->failed();
        $monitor->failed();
        self::assertSame(1, $built);
        self::assertSame(1, $monitor->failed()['total']);
    }

    // ---- updates

    private function checker(?string $source, MockHttpClient $http, ?ArrayAdapter $cache = null): UpdateChecker
    {
        return new UpdateChecker($source, $http, $cache ?? new ArrayAdapter());
    }

    #[Test]
    public function updatesAreOffByDefault(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('no request when the source is off');
        });
        $r = $this->checker(null, $http)->check('1.0.0');

        self::assertFalse($r['enabled']);
        self::assertFalse($r['hasUpdate']);
        self::assertSame('Пакет пока не опубликован', $r['message']);
        self::assertSame('Пакет пока не опубликован', $this->checker('', $http)->check('1.0.0')['message']);
    }

    #[Test]
    public function withoutAnHttpClientTheCheckSaysWhatIsMissing(): void
    {
        $r = (new UpdateChecker('https://updates.example.com/itk.json', null, new ArrayAdapter()))->check('1.0.0');

        self::assertTrue($r['enabled']);
        self::assertSame('Для проверки обновлений нужен пакет symfony/http-client', $r['message']);
    }

    #[Test]
    public function aWrongSourceAddressIsSaidSo(): void
    {
        $r = $this->checker('file:///etc/passwd', new MockHttpClient())->check('1.0.0');

        self::assertSame('Адрес для проверки обновлений указан неверно', $r['message']);
    }

    #[Test]
    public function aNewerVersionIsReportedAndTheAnswerIsKeptFor12Hours(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) \json_encode(['version' => 'v1.4.0']));
        });
        $checker = $this->checker('https://updates.example.com/itk.json', $http);

        $r = $checker->check('1.2.0');
        self::assertTrue($r['hasUpdate']);
        self::assertSame('1.4.0', $r['latest']);
        self::assertSame('Доступна версия 1.4.0', $r['message']);
        self::assertNotNull($r['checkedAt']);

        $again = $checker->check('1.4.0');
        self::assertFalse($again['hasUpdate']);
        self::assertSame('Установлена последняя версия', $again['message']);
        self::assertSame(1, $calls, 'the second look comes from the cache');

        $checker->check('1.2.0', true);
        self::assertSame(2, $calls, 'refresh asks again');
    }

    #[Test]
    public function packagistAnswersAreUnderstoodAndPreReleasesSkipped(): void
    {
        $body = ['packages' => ['itk/cms' => [['version' => '2.0.0-beta1'], ['version' => '1.9.0'], ['version' => 'dev-main'], ['version' => '1.10.0']]]];
        $http = new MockHttpClient(new MockResponse((string) \json_encode($body)));

        $r = $this->checker('https://repo.example.com/p2/itk/cms.json', $http)->check('1.9.0');

        self::assertSame('1.10.0', $r['latest']);
        self::assertTrue($r['hasUpdate']);
    }

    #[Test]
    public function aDevVersionCannotBeComparedAndAFailedCheckSaysSo(): void
    {
        $http = new MockHttpClient(new MockResponse((string) \json_encode(['version' => '1.0.0'])));
        $dev = $this->checker('https://updates.example.com/itk.json', $http)->check('dev-main');
        self::assertFalse($dev['hasUpdate']);
        self::assertSame('1.0.0', $dev['latest']);
        self::assertStringContainsString('версия для разработки', $dev['message']);

        $down = $this->checker('https://updates.example.com/itk.json', new MockHttpClient(new MockResponse('', ['http_code' => 500])))->check('1.0.0');
        self::assertFalse($down['hasUpdate']);
        self::assertNull($down['latest']);
        self::assertStringContainsString('Не получилось проверить', $down['message']);
    }
}

final class FailedHealthMonitorTransport implements ListableReceiverInterface, MessageCountAwareInterface
{
    /** @var array<string, Envelope> */
    public array $items = [];
    /** @var list<string> */
    public array $acked = [];
    /** @var list<string> */
    public array $rejected = [];

    public function __construct(Envelope ...$envelopes)
    {
        foreach ($envelopes as $e) {
            $this->items[(string) $e->last(TransportMessageIdStamp::class)?->getId()] = $e;
        }
    }

    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
        $this->acked[] = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
    }

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
    }

    public function all(?int $limit = null): iterable
    {
        return \array_values($this->items);
    }

    public function find(mixed $id): ?Envelope
    {
        return $this->items[(string) $id] ?? null;
    }

    public function getMessageCount(): int
    {
        return \count($this->items);
    }
}

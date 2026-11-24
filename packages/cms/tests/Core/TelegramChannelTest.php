<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request as RequestEntity;
use Itk\Cms\Notification\Channel\TelegramChannel;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\FailingMessageBus;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * The Telegram channel queues a delivery only when there is a Telegram to deliver to. A site without it keeps the request «pending» even when the queue itself is down.
 */
final class TelegramChannelTest extends WebTestCase
{
    /** @var list<int> */
    private array $requestIds = [];
    /** @var array<string, array{bool, mixed}> */
    private array $saved = [];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }

    protected function tearDown(): void
    {
        if (null !== static::$kernel) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            foreach ($this->requestIds as $id) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.dedupeKey = :k')->setParameter('k', 'telegram.failed:' . $id)->execute();
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id = :i')->setParameter('i', $id)->execute();
            }
            $settings = self::getContainer()->get(SettingsService::class);
            foreach ($this->saved as $key => [$existed, $value]) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', $key)->execute();
                if ($existed) {
                    $settings->set($key, $value);
                }
            }
        }
        parent::tearDown();
    }

    private function request(): RequestEntity
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $settings = self::getContainer()->get(SettingsService::class);
        foreach (['tgRoutes' => ['*' => '-1001234567890'], 'tgOn' => true] as $key => $value) {
            $this->saved[$key] ??= [$settings->isSet($key), $settings->get($key)];
            $settings->set($key, $value);
        }
        $r = new RequestEntity('call');
        $r->phone = '+7(900)000-00-31';
        $r->data = ['phone' => '+7(900)000-00-31'];
        $em->persist($r);
        $em->flush();
        $this->requestIds[] = (int) $r->id;

        return $r;
    }

    private function channel(MessageBusInterface $bus, ?\Closure $chatter): TelegramChannel
    {
        $notifier = new TelegramNotifier(
            self::getContainer()->get(Schema::class),
            self::getContainer()->get(SettingsService::class),
            new NullLogger(),
            $chatter,
            self::getContainer()->get('event_dispatcher'),
            true,
        );

        return new TelegramChannel(self::getContainer()->get(EntityManagerInterface::class), $bus, self::getContainer()->get('event_dispatcher'), new NullLogger(), $notifier);
    }

    #[Test]
    public function aSiteWithoutTelegramDoesNotQueueAndDoesNotFailWhenTheQueueIsDown(): void
    {
        self::bootKernel();
        $r = $this->request();
        $bus = new FailingMessageBus();

        $this->channel($bus, static fn (): ?object => null)->send(new Notification(Notification::REQUEST_NEW, 'Новая заявка', payload: ['requestId' => $r->id]));

        self::assertSame(0, $bus->attempts, 'nothing to deliver: no message in the queue');
        self::assertSame(RequestEntity::DELIVERY_PENDING, $r->delivery);
        self::assertNull($r->deliveryError);
    }

    #[Test]
    public function aConnectedTelegramWithADownQueueStillMarksTheRequestFailed(): void
    {
        self::bootKernel();
        $r = $this->request();
        $bus = new FailingMessageBus();
        $transport = new class implements TransportInterface {
            public function send(MessageInterface $message): SentMessage
            {
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

        $this->channel($bus, static fn (): TransportInterface => $transport)->send(new Notification(Notification::REQUEST_NEW, 'Новая заявка', payload: ['requestId' => $r->id]));

        self::assertSame(1, $bus->attempts);
        self::assertSame(RequestEntity::DELIVERY_FAILED, $r->delivery);
        self::assertStringNotContainsString('Queue is down', (string) $r->deliveryError, 'broker diagnostics are not exposed to staff');
        self::assertStringContainsString(\Itk\Cms\I18n\I18n::t('http.request_queue_failed'), (string) $r->deliveryError);
        self::assertSame(['telegram'], $r->queuedChannels(), 'the ambiguous publication remains recoverable');
        self::assertSame(1, (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM itk_request_queue WHERE request_id = ?', [$r->id]));
    }
}

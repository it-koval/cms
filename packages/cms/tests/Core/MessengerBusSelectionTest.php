<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\GenerateCriticalCss;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\IndexNow\QueueIndexNow;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Itk\Cms\KnowledgeBase\QueueOnContentChange;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Media\GenerateImageVariants;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Notification\Channel\MaxChannel;
use Itk\Cms\Notification\Channel\TelegramChannel;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Request\SendRequestNotification;
use Itk\Cms\Security\PasswordRecovery;
use Itk\Cms\Security\RequestPasswordLink;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class MessengerBusSelectionTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', ['messenger' => [
            'default_bus' => 'site.commands',
            'buses' => ['site.commands' => null, 'site.events' => null],
            'transports' => ['async' => 'in-memory://', 'critical_css' => 'in-memory://', 'knowledge_base' => 'in-memory://'],
        ]]);
    }

    #[Test]
    public function producersAndHandlersWorkWithTwoSiteBusesAndNoHardcodedDefault(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $bus = $container->get(MessageBusInterface::class);
        self::assertSame($container->get('site.commands'), $bus);
        foreach ([CriticalCssService::class, QueueIndexNow::class, QueueOnContentChange::class, SiteMailer::class, PasswordRecovery::class, RequestService::class, MediaService::class, TelegramChannel::class, MaxChannel::class] as $producer) {
            self::assertSame($bus, (new \ReflectionProperty($producer, 'bus'))->getValue($container->get($producer)), $producer . ' respects framework.messenger.default_bus');
        }
        $messages = [
            new GenerateCriticalCss('f2-missing-version'),
            new PingIndexNow('/f2-missing-path'),
            new SyncKnowledge('page', 'f2-missing-page', 'ru'),
            new SendMail('f2-no-recipient@example.com', 'F2', 'F2', '<p>F2</p>', SendMail::TEST),
            new RequestPasswordLink('f2-' . \bin2hex(\random_bytes(6)) . '@example.com', 'http://localhost'),
            new SendRequestNotification(0),
            new GenerateImageVariants('f2-missing-image'),
        ];
        foreach (['site.commands', 'site.events'] as $name) {
            $handlers = $container->get($name . '.messenger.handlers_locator');
            foreach ($messages as $message) {
                self::assertCount(1, \iterator_to_array($handlers->getHandlers(new Envelope($message))), $name . ': ' . $message::class);
            }
            $handled = $container->get($name)->dispatch(new Envelope($messages[4], [new ReceivedStamp('async')]));
            self::assertCount(1, $handled->all(HandledStamp::class), 'one received message executes one handler on ' . $name);
        }
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

use Doctrine\DBAL\Connection;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Notification\Channel\DatabaseChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Hands a notification to every channel that supports it: the tagged services, then the classes extensions named in Registry::notificationChannels(). */
final class Notifier implements NotifierInterface
{
    /** @var list<NotificationChannelInterface>|null */
    private ?array $channels = null;

    /** @param iterable<NotificationChannelInterface> $tagged */
    public function __construct(
        #[AutowireIterator('itk.notification_channel')]
        private readonly iterable $tagged,
        private readonly Registry $extensions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(Notification $notification, ?Connection $connection = null, bool $retryDatabaseFailure = false): void
    {
        foreach ($this->channels() as $channel) {
            try {
                if ($channel->supports($notification)) {
                    if ($channel instanceof DatabaseChannel) {
                        $channel->send($notification, $connection);
                    } else {
                        $channel->send($notification);
                    }
                }
            } catch (\Throwable $e) {
                if ($retryDatabaseFailure && $channel instanceof DatabaseChannel) {
                    throw $e;
                }
                $this->logger->error('Notification channel {channel} failed for {type}: {error}', ['channel' => $channel->name(), 'type' => $notification->type, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return list<NotificationChannelInterface> */
    public function channels(): array
    {
        if (null !== $this->channels) {
            return $this->channels;
        }
        $byClass = [];
        foreach ($this->tagged as $channel) {
            $byClass[$channel::class] = $channel;
        }
        foreach ($this->extensions->channelClasses() as $class) {
            if (isset($byClass[$class])) {
                continue;
            }
            if (!\is_subclass_of($class, NotificationChannelInterface::class)) {
                $this->logger->error('"{class}" from notificationChannels() is not a NotificationChannelInterface.', ['class' => $class]);
                continue;
            }
            $byClass[$class] = new $class();
        }

        return $this->channels = \array_values($byClass);
    }
}

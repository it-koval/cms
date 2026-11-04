<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where notifications go. A service that implements this is tagged `itk.notification_channel` by itself (autoconfigure);
 * a class that is not a service can be named in Registry::notificationChannels() by an extension.
 */
#[AutoconfigureTag('itk.notification_channel')]
interface NotificationChannelInterface
{
    /** Short name for logs ('database', 'telegram'). */
    public function name(): string;

    public function supports(Notification $notification): bool;

    /** Delivers the notification. An exception is logged by the notifier and does not stop the other channels. */
    public function send(Notification $notification): void;
}

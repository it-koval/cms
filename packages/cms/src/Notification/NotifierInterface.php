<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

/**
 * The single door for messages to the staff. Core code and the site call notify(); every channel that supports the message gets it.
 * A channel that fails never breaks the others or the caller. The site may replace the service (alias NotifierInterface) or only add channels.
 */
interface NotifierInterface
{
    public function notify(Notification $notification): void;
}

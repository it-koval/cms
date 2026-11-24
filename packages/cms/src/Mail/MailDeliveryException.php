<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

/**
 * A letter could not be sent. The message is already cleaned of passwords and is meant for the screen; there is no `previous`, so no secret travels with it.
 *
 * @internal
 */
class MailDeliveryException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $safeToRetry = false)
    {
        parent::__construct($message);
    }
}

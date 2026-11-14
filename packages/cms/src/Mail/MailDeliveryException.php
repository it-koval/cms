<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

/** A letter could not be sent. The message is already cleaned of passwords and is meant for the screen; there is no `previous`, so no secret travels with it. */
final class MailDeliveryException extends \RuntimeException
{
}

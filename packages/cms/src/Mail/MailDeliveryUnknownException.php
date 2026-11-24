<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A server may have accepted the email; require inspection rather than an automatic resend.
 *
 * @internal
 */
final class MailDeliveryUnknownException extends MailDeliveryException implements UnrecoverableExceptionInterface
{
}

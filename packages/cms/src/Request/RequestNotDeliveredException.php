<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

/**
 * Thrown by the handler so that Messenger retries the delivery (retry_strategy of the async transport).
 *
 * @internal
 */
final class RequestNotDeliveredException extends \RuntimeException
{
}

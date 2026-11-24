<?php

declare(strict_types=1);

namespace Itk\Cms\Request\Max;

/**
 * MAX did not take a message (or there is no bot to ask). The text is fit to be shown and logged.
 *
 * @internal
 */
final class MaxDeliveryException extends \RuntimeException
{
}

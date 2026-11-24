<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

/**
 * The provider may have accepted this message. Sending it again could duplicate it.
 *
 * @internal
 */
final class DeliveryUncertainException extends \RuntimeException
{
}

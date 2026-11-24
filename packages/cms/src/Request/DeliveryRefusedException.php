<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

/** A confirmed provider refusal, distinguished from an error saving delivery progress. */
final class DeliveryRefusedException extends \RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

/** Before a record changes: `record` is the current state, `data` the incoming change — change it. */
final class RecordUpdating extends RecordEvent
{
}

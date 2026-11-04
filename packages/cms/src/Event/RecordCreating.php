<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

/** Before a record is created (no `record` yet; `data` is what is going to be stored — change it). */
final class RecordCreating extends RecordEvent
{
}

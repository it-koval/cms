<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * Which pages a caller wants run, by their Freshness. The whole retry policy in one table:
 *   Outdated   Missing | Stale             the publish listener and the live render: a failure is never re-run on the same input by itself
 *   NotReady   Missing | Stale | Failed    the command without options: this is how failed pages are retried
 *   Always     everything                  the command --all (for example after a crittr upgrade the basis cannot see)
 */
enum When
{
    case Outdated;
    case NotReady;
    case Always;

    public function admits(Freshness $state): bool
    {
        return match ($this) {
            self::Outdated => $state->wantsRun(),
            self::NotReady => Freshness::Ready !== $state,
            self::Always => true,
        };
    }

    /** May the worker stamp the old styles on a new basis when the rendered HTML and CSS are byte-identical (no Chrome)? Not for --all. */
    public function reusesIdenticalInputs(): bool
    {
        return self::Always !== $this;
    }

    /** The wider of two asks for one page (several messages about one page in one run). */
    public function widest(self $other): self
    {
        $rank = [self::Outdated->name => 0, self::NotReady->name => 1, self::Always->name => 2];

        return $rank[$this->name] >= $rank[$other->name] ? $this : $other;
    }
}

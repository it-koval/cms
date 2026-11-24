<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * Which pages a caller wants run, by their Freshness. The whole retry policy in one table:
 *   Outdated   Missing | Stale             the publish listener and the live render: a failure is never re-run on the same input by itself
 *   NotReady   Missing | Stale | Failed    the command without options: this is how failed pages are retried
 *   Always     everything                  the command --all (for example after a crittr upgrade the basis cannot see)
 * Public policy cases for GenerateCriticalCss::$when; evaluation helpers are internal.
 */
enum When
{
    case Outdated;
    case NotReady;
    case Always;

    /** @internal Evaluates the policy against the worker's current freshness. */
    public function admits(Freshness $state): bool
    {
        return match ($this) {
            self::Outdated => $state->wantsRun(),
            self::NotReady => Freshness::Ready !== $state,
            self::Always => true,
        };
    }

    /**
     * May the worker stamp the old styles on a new basis when the rendered HTML and CSS are byte-identical (no Chrome)? Not for --all.
     *
     * @internal
     */
    public function reusesIdenticalInputs(): bool
    {
        return self::Always !== $this;
    }
}

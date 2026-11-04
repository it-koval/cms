<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

use Itk\Cms\Entity\CriticalCss;

/**
 * The derived state of a page's critical CSS, never stored: computed from the row and the basis the page wants now.
 *
 *   Missing  no row (never generated, or the feature was just turned on)
 *   Stale    a row for another basis (republished, new CSS, deploy, new viewports)
 *   Ready    styles for this very basis: the only state that is inlined
 *   Failed   this very basis was tried and did not work: not retried by itself (no Chrome loop), only by the command or by the next basis
 *
 * The one table of policy, used by the render (inline?), by the request side (ask the worker?) and by the worker (run?).
 */
enum Freshness
{
    case Missing;
    case Stale;
    case Ready;
    case Failed;

    public static function of(?CriticalCss $row, Basis $wanted): self
    {
        if (null === $row) {
            return self::Missing;
        }
        if (!$wanted->equals(Basis::fromStored($row->basis))) {
            return self::Stale;
        }

        return CriticalCss::READY === $row->status ? self::Ready : self::Failed;
    }

    /** Does the request side (publish listener, live render) ask the worker for a run? */
    public function wantsRun(): bool
    {
        return self::Missing === $this || self::Stale === $this;
    }
}

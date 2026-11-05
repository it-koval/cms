<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * The derived state of one document, never stored: from the stored basis (null = no row) and the plan (null = not live, excluded, or schema gone).
 *
 *   Missing  live, no row                  embed and write
 *   Stale    live, row of another basis    embed what is new, rewrite (republish with changes, a deploy, a model change)
 *   Current  live, row of this very basis  nothing
 *   Orphan   a row, not live               delete (unpublished, excluded by config; a deleted source has no row left: the foreign key took it)
 *
 * There is no Failed: an embedding failure is transient and belongs to the Messenger retry, not to the row.
 *
 * @internal
 */
enum Freshness
{
    case Missing;
    case Stale;
    case Current;
    case Orphan;

    /** null when there is neither a row nor a live source: nothing to say, nothing to do. */
    public static function of(?Basis $stored, ?DocumentPlan $plan): ?self
    {
        return match (true) {
            null === $stored && null === $plan => null,
            null === $stored => self::Missing,
            null === $plan => self::Orphan,
            $stored->equals($plan->basis) => self::Current,
            default => self::Stale,
        };
    }

    public function needsWork(): bool
    {
        return self::Current !== $this;
    }
}

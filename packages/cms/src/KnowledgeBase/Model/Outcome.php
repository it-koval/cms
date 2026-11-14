<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * What Indexer::sync() did with one source.
 *
 * @internal
 */
enum Outcome
{
    /** rows (re)written for the current basis */
    case Written;
    /** the stored basis was already the planned one */
    case Unchanged;
    /** an Orphan's row deleted */
    case Removed;
    /** the source changed between plan and write (or was deleted): nothing written; the change queued its own message */
    case Superseded;
    /** extraction or the write threw for this source only; the handler nacks just this ref */
    case Failed;
}

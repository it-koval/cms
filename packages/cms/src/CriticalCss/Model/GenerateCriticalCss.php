<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * Messenger message (transport `critical_css`, default Messenger bus): bring the critical CSS of one language version of a page up to date.
 * It carries no basis and no URL: the handler re-reads everything, so a late, duplicated or replayed message is a cheap no-op.
 * The class name is public: a site may route it to a transport of its own.
 */
final class GenerateCriticalCss
{
    public function __construct(
        public readonly string $translationId,
        /** which states the handler still runs on when the message arrives (the row may have moved on since the ask) */
        public readonly When $when = When::Outdated,
    ) {
    }
}

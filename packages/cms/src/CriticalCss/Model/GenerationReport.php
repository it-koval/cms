<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/** What one CriticalCssGenerator::generate() call did: the command prints it, the handler logs the failures. */
final readonly class GenerationReport
{
    /**
     * @param int $generated pages that went through Chrome and are Ready now
     * @param int $reused pages whose rendered HTML and CSS were unchanged: the new basis stamped on the old styles, no Chrome
     * @param int $skipped already Ready or Failed for the current basis, not live any more, or gone
     * @param array<string, string> $failed translation id => reason
     */
    public function __construct(
        public int $generated,
        public int $reused,
        public int $skipped,
        public array $failed,
    ) {
    }
}

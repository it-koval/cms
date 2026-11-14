<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\McpToolException;

/**
 * Turns a list of problems into the one error the model reads.
 *
 * @internal
 */
trait FixInput
{
    /** @param list<string> $problems */
    protected static function failIf(array $problems): void
    {
        if ([] !== $problems) {
            throw new McpToolException("Nothing was saved. Fix these and call again:\n- " . \implode("\n- ", \array_slice($problems, 0, 25)));
        }
    }
}

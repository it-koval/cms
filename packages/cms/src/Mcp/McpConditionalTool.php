<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

/** A tool that is only offered to some keys beyond the scope: for example only to people who have the right to requests. */
interface McpConditionalTool
{
    public function isAvailableTo(McpContext $context): bool;
}

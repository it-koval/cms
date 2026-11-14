<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

/** A mistake in the list of tools of a site (McpToolRegistry). The text holds only names of tools, classes and scopes, so it may go to the log. */
final class McpToolListError extends \LogicException
{
}

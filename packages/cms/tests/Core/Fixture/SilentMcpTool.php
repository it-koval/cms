<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;

/** A tool of a site written before the hints became mandatory: it says nothing about what it does. */
final class SilentMcpTool extends AbstractMcpTool
{
    public function name(): string
    {
        return 'test_silent';
    }

    public function description(): string
    {
        return 'Test tool.';
    }

    /** @return array<array-key, mixed> */
    public function inputSchema(): array
    {
        return self::schema([]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        return [];
    }
}

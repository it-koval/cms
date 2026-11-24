<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;

/** Annotations that are not what the interface promises. */
final class BadHintsMcpTool extends AbstractMcpTool
{
    public function name(): string
    {
        return 'test_bad_hints';
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

    public function annotations(): array
    {
        return ['readOnly' => 'yes'];
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

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;

/** Asks for a scope that does not exist. */
final class BadScopeMcpTool extends AbstractMcpTool
{
    public function name(): string
    {
        return 'test_bad_scope';
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
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function requiredScope(): string
    {
        return 'admin';
    }

    public function call(array $args, McpContext $context): array
    {
        return [];
    }
}

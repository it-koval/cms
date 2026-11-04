<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;

/** Uses the name of EchoMcpTool: the registry must refuse the pair. */
final class TwinMcpTool extends AbstractMcpTool
{
    public function name(): string
    {
        return 'test_echo';
    }

    public function description(): string
    {
        return 'Test tool.';
    }

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
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        return [];
    }
}

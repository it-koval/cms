<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;

/** A tool of a "site", named in Registry::mcpTools(). */
final class EchoMcpTool extends AbstractMcpTool
{
    public function name(): string
    {
        return 'test_echo';
    }

    public function description(): string
    {
        return 'Test tool: repeats the text.';
    }

    public function inputSchema(): array
    {
        return self::schema(['text' => self::string('Text to repeat.', 100)], ['text']);
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
        return ['echo' => (string) $args['text'], 'user' => $context->user()->email];
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpToolException;

/** @internal */
final class RequestUpdateTool extends RequestsTool
{
    public function name(): string
    {
        return 'request_update';
    }

    public function description(): string
    {
        return 'Set the work status of a request (new | in_work | closed | spam) and/or the private staff comment (≤500 characters; an empty string clears it). Needs the right to edit requests. The visitor is not notified.';
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Request id.'],
            'status' => ['type' => 'string', 'enum' => ['new', 'in_work', 'closed', 'spam'], 'description' => 'New work status.'],
            'comment' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Staff comment; replaces the old one.'],
        ], ['id']);
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => true];
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('requests', 'edit');
        if (!isset($args['status']) && !\array_key_exists('comment', $args)) {
            throw new McpToolException('Nothing to change: send status and/or comment.');
        }
        $r = $this->requests->update((int) $args['id'], $args['status'] ?? null, \array_key_exists('comment', $args) ? (string) $args['comment'] : null);

        return ['updated' => true] + self::row($this->requests->present($r));
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\History\HistoryAdmin;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;

/** @internal */
final class HistoryListTool extends AbstractMcpTool
{
    public function __construct(private readonly HistoryAdmin $history)
    {
    }

    public function name(): string
    {
        return 'history_list';
    }

    public function description(): string
    {
        return 'Show the history of changes of a page or a collection record, newest first: who changed what and when, and whether it was done through an AI assistant. '
            . 'Use it to see what happened to a page before editing it. Restoring versions is not possible through this server.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'kind' => ['type' => 'string', 'enum' => ['page', 'item'], 'description' => 'page = a page, item = a collection record.'],
            'id' => self::string('Page id or record id.', 64),
            'locale' => self::localeProperty(),
            'limit' => self::limitProperty(20, 100),
            'offset' => self::offsetProperty(),
        ], ['kind', 'id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $limit = self::limit($args, 20, 100);
        $offset = self::offset($args);
        // HistoryAdmin checks the right to see the thing itself (the page template, the collection) and leaves out the fields the person may not see
        $res = $this->history->entries((string) $args['kind'], (string) $args['id'], self::locale($args), $limit, $offset);
        $items = [];
        foreach ($res['items'] as $e) {
            $items[] = [
                'id' => $e['id'], 'action' => $e['action'], 'by' => $e['userName'] ?? 'system', 'viaAssistant' => 'mcp' === $e['via'] ? ($e['viaName'] ?? true) : false,
                'at' => $e['updatedAt'], 'title' => Format::text($e['title'], 100),
                'changes' => \array_map(static fn (array $r): string => Format::text(($r['label'] ?? $r['kind'] ?? '') . ('' !== ($r['val'] ?? '') ? ': ' . Format::text($r['val'], 60) : ''), 140), \array_slice($e['summary'], 0, 6)),
            ];
        }

        return ['items' => $items, 'total' => $res['total'], 'offset' => $offset, 'limit' => $limit, 'hasMore' => $offset + $limit < $res['total']];
    }
}

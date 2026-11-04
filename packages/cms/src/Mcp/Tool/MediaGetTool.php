<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Media\MediaService;

/** @internal */
final class MediaGetTool extends AbstractMcpTool
{
    public function __construct(private readonly MediaService $media)
    {
    }

    public function name(): string
    {
        return 'media_get';
    }

    public function description(): string
    {
        return 'Get one file of the media library with the places where it is used (pages, records, settings).';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema(['id' => self::string('Media id (from media_search).', 64)], ['id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('media', 'view');
        $p = $this->media->present($this->media->find((string) $args['id']), true);
        $used = \array_map(static fn (array $u): array => ['label' => Format::text($u['label'], 120), 'where' => $u['to']], \array_slice($p['used'], 0, 20));

        return MediaSearchTool::row($p) + ['originalName' => Format::text($p['originalName'], 150), 'mime' => $p['mime'], 'folderId' => $p['folderId'], 'usedIn' => $used, 'usedCount' => \count($p['used'])];
    }
}

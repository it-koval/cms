<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Media\MediaService;

/** @internal */
final class MediaSearchTool extends AbstractMcpTool
{
    public function __construct(private readonly MediaService $media)
    {
    }

    public function name(): string
    {
        return 'media_search';
    }

    public function description(): string
    {
        return 'Search the media library (photos and PDFs). Returns id, src (the address to put into image fields), name, alt text and size. Newest first. Use media_get to see where a file is used.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'q' => self::string('Text to look for in the file name or alt text.', 100),
            'type' => ['type' => 'string', 'enum' => ['img', 'pdf'], 'description' => 'Only photos or only PDFs.'],
            'limit' => self::limitProperty(30, 100),
            'offset' => self::offsetProperty(),
        ]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('media', 'view');
        $rows = [];
        foreach ($this->media->list((string) ($args['q'] ?? ''), (string) ($args['type'] ?? '')) as $m) {
            $rows[] = self::row($this->media->present($m));
        }

        return Format::page($rows, self::offset($args), self::limit($args, 30, 100));
    }

    /**
     * @param array<string, mixed> $p MediaService::present()
     *
     * @return array<string, mixed>
     */
    public static function row(array $p): array
    {
        return [
            'id' => $p['id'], 'src' => $p['src'], 'name' => Format::text($p['name'], 100), 'alt' => Format::text($p['alt'], 150),
            'type' => $p['type'], 'width' => $p['w'], 'height' => $p['h'], 'kb' => $p['kb'], 'createdAt' => $p['createdAt'],
        ];
    }
}

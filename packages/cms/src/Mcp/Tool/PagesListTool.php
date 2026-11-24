<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Mcp\Support\Shapes;

/** @internal */
final class PagesListTool extends AbstractMcpTool
{
    public function __construct(
        private readonly PageService $pages,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'pages_list';
    }

    public function description(): string
    {
        return 'List pages of the site (compact rows: id, title, url, template, state, number of sections). state is draft | published | published_with_unpublished_changes. '
            . 'Filter by template, parent page, state or a text in the title/address. Use page_get for the content of one page.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'template' => self::string('Only pages of this template key.', 64),
            'parent_id' => self::string('Only direct children of this page id; "root" for top-level pages.', 64),
            'state' => ['type' => 'string', 'enum' => ['draft', 'published', 'published_with_unpublished_changes'], 'description' => 'Only pages in this state.'],
            'q' => self::string('Text to look for in the title or address (case-insensitive).', 100),
            'locale' => self::localeProperty(),
            'limit' => self::limitProperty(50, 200),
            'offset' => self::offsetProperty(),
        ]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('pages', 'view');
        $locale = self::locale($args);
        $parent = $args['parent_id'] ?? null;
        $q = \mb_strtolower(\trim((string) ($args['q'] ?? '')));
        $rows = [];
        foreach ($this->pages->list($locale, $args['template'] ?? null, \in_array($parent, [null, 'root'], true) ? null : (string) $parent, $context->viewer()) as $row) {
            if ('root' === $parent && null !== $row['parentId']) {
                continue;
            }
            $r = $this->shapes->pageRow($row);
            if (isset($args['state']) && $r['state'] !== $args['state']) {
                continue;
            }
            if ('' !== $q && !\str_contains(\mb_strtolower($r['title'] . ' ' . $r['url']), $q)) {
                continue;
            }
            $rows[] = $r;
        }

        return Format::page($rows, self::offset($args), self::limit($args, 50, 200));
    }
}

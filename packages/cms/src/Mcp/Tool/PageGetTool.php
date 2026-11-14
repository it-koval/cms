<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\Shapes;

/** @internal */
final class PageGetTool extends AbstractMcpTool
{
    public function __construct(
        private readonly PageService $pages,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'page_get';
    }

    public function description(): string
    {
        return 'Get one page as it is in the DRAFT (what an editor sees): title, url, SEO, template fields, and its sections with their ids and data. '
            . 'state tells whether it is live; for a published page "changes" lists what differs between the draft and the live version. '
            . 'Use sections="outline" to see only ids, types and a short summary, or section_id to get one section in full.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'id' => self::string('Page id (from pages_list).', 64),
            'locale' => self::localeProperty(),
            'sections' => ['type' => 'string', 'enum' => ['full', 'outline'], 'description' => 'full (default): all section data; outline: id, type, hidden and a one-line summary only.'],
            'section_id' => self::string('Return only this section (in full).', 64),
        ], ['id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $id = (string) $args['id'];
        $context->require('pages', 'view', $this->pages->find($id)->template);
        $view = $this->pages->getDraft($id, self::locale($args));
        if (null === $view['page']) {
            throw new McpToolException('This page has no version in the language "' . $view['locale'] . '". Available: ' . \implode(', ', \array_keys(\array_filter($view['locales']))));
        }
        $only = isset($args['section_id']) ? (string) $args['section_id'] : null;
        $out = $this->shapes->pageView($view, 'outline' !== ($args['sections'] ?? 'full'), $only);
        if (null !== $only && [] === $out['sections']) {
            throw new McpToolException('No section "' . $only . '" on this page. Sections: ' . \implode(', ', \array_column($this->shapes->page($view['page'], false)['sections'], 'id')));
        }

        return $out;
    }
}

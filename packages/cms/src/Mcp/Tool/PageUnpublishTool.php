<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Shapes;

/** @internal */
final class PageUnpublishTool extends AbstractMcpTool
{
    public function __construct(
        private readonly PageService $pages,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'page_unpublish';
    }

    public function description(): string
    {
        return 'TAKE a page OFF the site: the live version disappears from the site at once and the page stays as a draft (nothing is deleted; publish it again with page_publish). The home page cannot be taken off. '
            . 'Requires the publish right. Only do this when the person asked you to.';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema(['id' => self::string('Page id.', 64), 'locale' => self::localeProperty()], ['id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::PUBLISH;
    }

    public function call(array $args, McpContext $context): array
    {
        $id = (string) $args['id'];
        $locale = self::locale($args);
        $context->require('pages', 'publish', $this->pages->find($id)->template);
        $this->pages->unpublish($id, $locale);

        return ['unpublished' => true, 'page' => $this->shapes->pageView($this->pages->getDraft($id, $locale), false)];
    }
}

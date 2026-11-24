<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Shapes;

/** @internal */
final class PagePublishTool extends AbstractMcpTool
{
    public function __construct(
        private readonly PageService $pages,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'page_publish';
    }

    public function description(): string
    {
        return 'PUBLISH a page: the current draft goes live on the site at once (the previous live version is replaced; if the address changed a redirect is made). '
            . 'Requires the publish right. Required fields must be filled, otherwise the error lists what is missing. Only publish what the person asked you to publish.';
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
        $this->pages->publish($id, $locale);

        return ['published' => true, 'page' => $this->shapes->pageView($this->pages->getDraft($id, $locale), false)];
    }
}

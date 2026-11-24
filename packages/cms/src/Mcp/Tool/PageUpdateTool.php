<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class PageUpdateTool extends AbstractMcpTool
{
    use FixInput;

    public function __construct(
        private readonly Schema $schema,
        private readonly PageService $pages,
        private readonly FieldInput $input,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'page_update';
    }

    public function description(): string
    {
        return 'Change the page-level data of a page DRAFT: title, url, SEO and template fields (only the keys you send change). Sections are changed with the page_section_* tools. '
            . 'Nothing goes live until the page is published. Returns the page with what differs from the live version.';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'id' => self::string('Page id.', 64),
            'title' => self::string('New page name (≤70).', 70),
            'url' => self::string('New address starting with / (≤80). A live page keeps its old address until published, then a redirect is made.', 80),
            'seo' => ['type' => 'object', 'description' => 'SEO: {"title": "≤70", "description": "≤160"}; keys you omit stay as they are.'],
            'fields' => ['type' => 'object', 'description' => 'Template fields to change, by key; fields you omit stay as they are.'],
            'locale' => self::localeProperty(),
        ], ['id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::WRITE;
    }

    public function call(array $args, McpContext $context): array
    {
        return $this->pages->locked((string) $args['id'], fn (): array => $this->change($args, $context));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function change(array $args, McpContext $context): array
    {
        $id = (string) $args['id'];
        $locale = self::locale($args);
        $page = $this->pages->find($id);
        $context->require('pages', 'edit', $page->template);
        if (!isset($args['title']) && !isset($args['url']) && !isset($args['seo']) && !isset($args['fields'])) {
            throw new McpToolException('Nothing to change: send title, url, seo or fields.');
        }
        $tpl = $this->schema->template($page->template);
        $problems = [];
        if (isset($args['url']) && !\str_starts_with((string) $args['url'], '/')) {
            $problems[] = 'url: must start with /';
        }
        if (isset($args['title']) && '' === \trim((string) $args['title'])) {
            $problems[] = 'title: must not be empty';
        }
        if (isset($args['fields'])) {
            $current = (array) $this->pages->get($id, $locale)['fields'];
            $problems = [...$problems, ...$this->input->problems($tpl?->fieldList() ?? [], (array) $args['fields'], FieldInput::srcsIn($current, $tpl?->fieldList() ?? []), 'fields', $current)];
            if ([] === $problems) {
                [$args['fields'], $lost] = $this->input->keepRaw($tpl?->fieldList() ?? [], (array) $args['fields'], $current, 'fields');
                $problems = [...$problems, ...$lost];
            }
        }
        $problems = [...$problems, ...PageCreateTool::seoProblems($args['seo'] ?? null)];
        self::failIf($problems);

        $patch = [];
        foreach (['title', 'url', 'fields'] as $k) {
            if (isset($args[$k])) {
                $patch[$k] = $args[$k];
            }
        }
        if (isset($args['seo'])) {
            // the service replaces the whole SEO block: keep the half that was not sent
            $patch['seo'] = (array) $args['seo'] + (array) $this->pages->get($id, $locale)['seo'];
        }
        $this->pages->update($id, $locale, $patch);

        return ['updated' => true, 'page' => $this->shapes->pageView($this->pages->getDraft($id, $locale), false)];
    }
}

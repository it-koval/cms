<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;

/** @internal */
final class PageCreateTool extends AbstractMcpTool
{
    use FixInput;

    public function __construct(
        private readonly Schema $schema,
        private readonly PageService $pages,
        private readonly FieldValues $values,
        private readonly FieldInput $input,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'page_create';
    }

    public function description(): string
    {
        return 'Create a new page as a DRAFT in the main language (it is not on the site until published). Needs title, url (starts with /) and optionally a template (default "default"), a parent page, template fields, SEO and sections. '
            . 'Check site_schema for the template and its section types first. Without sections a first-screen section with the title is added. Returns the new page.';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => false, 'idempotent' => false];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'title' => self::string('Page name (≤70 characters).', 70),
            'url' => self::string('Address starting with /, e.g. /about-us (≤80). Must be free.', 80),
            'template' => self::string('Template key from site_schema; default "default".', 64),
            'parent_id' => self::string('Id of the parent page; omit for the top level (the template decides where it may stand).', 64),
            'fields' => ['type' => 'object', 'description' => 'Template fields by key (see site_schema kind=template).'],
            'seo' => ['type' => 'object', 'description' => 'SEO: {"title": "≤70", "description": "≤160"}.'],
            'sections' => ['type' => 'array', 'description' => 'Sections in order: [{"type": "<section type>", "data": {…fields…}, "hidden": false}]. See site_schema kind=section.', 'maxItems' => 40, 'items' => ['type' => 'object']],
        ], ['title', 'url']);
    }

    public function requiredScope(): string
    {
        return McpScopes::WRITE;
    }

    public function call(array $args, McpContext $context): array
    {
        $key = (string) ($args['template'] ?? 'default');
        $tpl = $this->schema->template($key) ?? throw new McpToolException('No such template: ' . $key . '. Templates: ' . \implode(', ', \array_keys($this->schema->templates())));
        $context->require('pages', 'edit', $key);

        $problems = [];
        if (null !== ($problem = PageService::addressShapeProblem(\trim((string) $args['url'])))) {
            $problems[] = 'url: ' . $problem;
        }
        if (isset($args['fields'])) {
            $problems = [...$problems, ...$this->input->problems($tpl->fieldList(), (array) $args['fields'], [], 'fields')];
        }
        $problems = [...$problems, ...self::seoProblems($args['seo'] ?? null)];
        $sections = null;
        if (isset($args['sections'])) {
            if (!$tpl->hasSections()) {
                $problems[] = 'sections: the template "' . $key . '" has no sections';
            } else {
                $sections = [];
                foreach ((array) $args['sections'] as $i => $s) {
                    $type = (string) ($s['type'] ?? '');
                    $def = $this->schema->section($type);
                    if (null === $def) {
                        $problems[] = \sprintf('sections[%d].type: unknown section type "%s"', $i, $type);
                        continue;
                    }
                    $data = (array) ($s['data'] ?? []);
                    foreach ($this->input->problems($def->fieldList(), $data, [], 'sections[' . $i . '].data') as $p) {
                        $problems[] = $p;
                    }
                    $sections[] = ['id' => Json::nid('s'), 'type' => $type, 'hidden' => !empty($s['hidden']), 'data' => $data + $this->values->defaults($def->fieldList())];
                }
            }
        }
        self::failIf($problems);

        $input = ['template' => $key, 'title' => (string) $args['title'], 'url' => (string) $args['url'], 'parentId' => $args['parent_id'] ?? null];
        if (isset($args['fields'])) {
            $input['fields'] = (array) $args['fields'];
        }
        if (isset($args['seo'])) {
            $input['seo'] = (array) $args['seo'];
        }
        if (null !== $sections) {
            $input['sections'] = $sections;
        }
        $page = $this->pages->create($input);

        return ['created' => true, 'note' => 'Saved as a draft; it is not on the site until published.', 'page' => $this->shapes->pageView($this->pages->getDraft($page['id']), false)];
    }

    /** @return list<string> */
    public static function seoProblems(mixed $seo): array
    {
        if (null === $seo) {
            return [];
        }
        if (!\is_array($seo)) {
            return ['seo: must be an object {title, description}'];
        }
        $out = [];
        foreach ($seo as $k => $v) {
            if (!\in_array($k, ['title', 'description'], true)) {
                $out[] = \sprintf('seo.%s: unknown key (title, description)', $k);
            } elseif (!\is_string($v)) {
                $out[] = \sprintf('seo.%s: must be a string', $k);
            } elseif (\mb_strlen($v) > ('title' === $k ? 70 : 160)) {
                $out[] = \sprintf('seo.%s: at most %d characters (got %d)', $k, 'title' === $k ? 70 : 160, \mb_strlen($v));
            }
        }

        return $out;
    }
}

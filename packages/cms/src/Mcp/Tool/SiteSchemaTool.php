<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\MenuService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldDescriber;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;

/** @internal */
final class SiteSchemaTool extends AbstractMcpTool
{
    public function __construct(
        private readonly Schema $schema,
        private readonly FieldDescriber $describer,
        private readonly MenuService $menus,
    ) {
    }

    public function name(): string
    {
        return 'site_schema';
    }

    public function description(): string
    {
        return 'Describe the structure of this site: page templates, section types, collections, forms, menu areas and settings groups, limited to what the key owner may see. '
            . 'Without arguments it returns a short overview (keys and names). Pass kind + key to get the full field list of one template, section type, collection or form: field keys, types, the format of each value, limits and options. '
            . 'Call this first, and again before writing to a section type or collection you have not seen.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'kind' => ['type' => 'string', 'enum' => ['template', 'section', 'collection', 'form'], 'description' => 'What to describe in full. Omit for the overview.'],
            'key' => self::string('Key of the template, section type, collection or form (from the overview).', 64),
        ]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $kind = $args['kind'] ?? null;
        if (null === $kind) {
            return $this->overview($context);
        }
        $key = (string) ($args['key'] ?? '');
        if ('' === $key) {
            throw new McpToolException('key is required together with kind');
        }

        return match ($kind) {
            'template' => $this->template($key, $context),
            'section' => $this->section($key, $context),
            'collection' => $this->collection($key, $context),
            'form' => $this->form($key, $context),
            default => throw new McpToolException(\sprintf('kind must be template, section, collection or form, got "%s"', \is_scalar($kind) ? (string) $kind : \get_debug_type($kind))),
        };
    }

    /** @return array<string, mixed> */
    private function overview(McpContext $c): array
    {
        $viewer = $c->viewer();
        $templates = [];
        $sections = [];
        if ($c->can('pages', 'view')) {
            foreach ($this->schema->templates() as $t) {
                if (!$c->can('pages', 'view', $t->key())) {
                    continue;
                }
                $templates[] = ['key' => $t->key(), 'name' => $t->title(), 'sections' => $t->hasSections(), 'fields' => \array_column($this->describer->describeAll($t->fieldList(), $viewer), 'key')]
                    + ($t->isRoot() ? ['home' => true] : []) + ($t->isSingle() ? ['single' => true] : []);
            }
            foreach ($this->schema->sections() as $s) {
                $sections[] = ['key' => $s->key(), 'name' => $s->name(), 'group' => $s->groupName()];
            }
        }
        $collections = [];
        foreach ($this->schema->collections() as $col) {
            if ($col->isVisibleTo($viewer) && $c->can('col:' . $col->key(), 'view')) {
                $collections[] = ['key' => $col->key(), 'title' => $col->title(), 'fields' => \count($col->fieldList())]
                    + ($col->isTree() ? ['tree' => true] : []) + (null !== $col->singletonId() ? ['single' => true] : []);
            }
        }
        $forms = [];
        if ($c->can('requests', 'view')) {
            foreach ($this->schema->forms() as $f) {
                $forms[] = ['key' => $f->key(), 'title' => $f->formTitle()];
            }
        }
        $menus = [];
        if ($c->can('set:menus', 'view')) {
            foreach ($this->menus->areas() as $a) {
                $menus[] = ['key' => $a['key'], 'name' => $a['name'], 'depth' => $a['depth']];
            }
        }
        $settings = [];
        foreach ($this->schema->settingsGroups() as $g) {
            if ($c->can('set:' . $g->key(), 'view')) {
                $settings[] = ['key' => $g->key(), 'title' => $g->groupTitle()];
            }
        }

        return [
            'mainLocale' => $this->schema->mainLocale(),
            'templates' => $templates, 'sections' => $sections, 'collections' => $collections, 'forms' => $forms, 'menuAreas' => $menus, 'settingsGroups' => $settings,
            'hint' => 'Call again with kind and key for the fields of one template, section type, collection or form.',
        ];
    }

    /** @return array<string, mixed> */
    private function template(string $key, McpContext $c): array
    {
        $t = $this->schema->template($key);
        if (null === $t || !$c->can('pages', 'view', $key)) {
            throw new McpToolException('No such template (or you may not see it): ' . $key);
        }
        $out = ['key' => $t->key(), 'name' => $t->title(), 'sections' => $t->hasSections(), 'parents' => $t->parentRule(), 'fields' => $this->describer->describeAll($t->fieldList(), $c->viewer())];
        if (null !== $t->h1Field()) {
            $out['headingField'] = $t->h1Field();
        }
        if ($t->isRoot()) {
            $out['home'] = true;
        }
        if ($t->isSingle()) {
            $out['single'] = true;
        }
        if ($t->isLeaf()) {
            $out['leaf'] = true;
        }
        $out['pageFields'] = 'Every page also has: title (string), url (string starting with /), seo.title (≤70), seo.description (≤160).';

        return $out;
    }

    /** @return array<string, mixed> */
    private function section(string $key, McpContext $c): array
    {
        $s = $this->schema->section($key);
        if (null === $s || !$c->can('pages', 'view')) {
            throw new McpToolException('No such section type (or you may not see it): ' . $key);
        }
        $out = ['key' => $s->key(), 'name' => $s->name(), 'group' => $s->groupName(), 'fields' => $this->describer->describeAll($s->fieldList(), $c->viewer())];
        if ('' !== $s->toArray()['about']) {
            $out['about'] = Format::text($s->toArray()['about'], 300);
        }
        if (null !== $s->h1Field()) {
            $out['carriesPageHeading'] = $s->h1Field();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function collection(string $key, McpContext $c): array
    {
        $col = $this->schema->collection($key);
        if (!$col instanceof Collection || !$col->isVisibleTo($c->viewer()) || !$c->can('col:' . $key, 'view')) {
            throw new McpToolException('No such collection (or you may not see it): ' . $key);
        }
        $filters = [];
        foreach ($col->filterFields() as $f) {
            if ($f->visibleTo($c->viewer())) {
                $filters[$f->key()] = $f->filterKind()->value ?? 'text';
            }
        }
        $sort = ['status', 'updatedAt', 'createdAt', 'position'];
        foreach ($col->fieldList() as $f) {
            if ($f->isSortable() && $f->visibleTo($c->viewer())) {
                $sort[] = $f->key();
            }
        }

        return [
            'key' => $col->key(), 'title' => $col->title(), 'titleField' => $col->titleFieldKey(), 'fields' => $this->describer->describeAll($col->fieldList(), $c->viewer()),
            'filters' => $filters ?: new \stdClass(), 'sortKeys' => $sort, 'searchFields' => $col->searchKeys(), 'tree' => $col->isTree(), 'single' => null !== $col->singletonId(),
            'rights' => ['edit' => $c->can('col:' . $key, 'edit'), 'publish' => $c->can('col:' . $key, 'publish')],
        ];
    }

    /** @return array<string, mixed> */
    private function form(string $key, McpContext $c): array
    {
        $f = $this->schema->form($key);
        if (null === $f || !$c->can('requests', 'view')) {
            throw new McpToolException('No such form (or you may not see it): ' . $key);
        }

        return ['key' => $f->key(), 'title' => $f->formTitle(), 'fields' => $this->describer->describeAll($f->fieldList(), $c->viewer())];
    }
}

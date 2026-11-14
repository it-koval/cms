<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class PageSectionUpdateTool extends SectionTool
{
    public function __construct(
        Schema $schema,
        PageService $pages,
        Shapes $shapes,
        private readonly FieldInput $input,
    ) {
        parent::__construct($schema, $pages, $shapes);
    }

    public function name(): string
    {
        return 'page_section_update';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => true];
    }

    public function description(): string
    {
        return 'Change fields of one section in the DRAFT of a page. data holds only the fields to change (the others stay); a list field (repeater, photos) is replaced as a whole by what you send, so send the full list. '
            . 'hidden hides or shows the section. Use page_get with section_id to see its current data first.' . self::EDITOR_NOTE;
    }

    public function inputSchema(): array
    {
        return self::schema(self::pageIdProperties() + [
            'section_id' => self::string('Section id (from page_get).', 64),
            'data' => ['type' => 'object', 'description' => 'Fields to change, by key.'],
            'hidden' => ['type' => 'boolean', 'description' => 'true to hide the section, false to show it.'],
        ], ['page_id', 'section_id']);
    }

    protected function change(array $args, McpContext $context): array
    {
        $locale = self::locale($args);
        $pageId = (string) $args['page_id'];
        if (!isset($args['data']) && !isset($args['hidden'])) {
            throw new McpToolException('Nothing to change: send data and/or hidden.');
        }
        [$sections, $template, $heading] = $this->open($pageId, $locale, $context);
        $i = $this->indexOf($sections, (string) $args['section_id']);
        $def = $this->schema->section((string) $sections[$i]['type']) ?? throw new McpToolException('This section has a type the site no longer knows.');
        $data = (array) ($args['data'] ?? []);
        self::failIf($this->input->problems($def->fieldList(), $data, $this->knownSrcs($sections), 'data', (array) $sections[$i]['data']));
        [$data, $lost] = $this->input->keepRaw($def->fieldList(), $data, (array) $sections[$i]['data'], 'data');
        self::failIf($lost);

        $sections[$i]['data'] = $data + $sections[$i]['data'];
        if (isset($args['hidden'])) {
            $sections[$i]['hidden'] = (bool) $args['hidden'];
        }

        return ['updated' => true] + $this->save($pageId, $locale, $sections, $template, $heading, $sections[$i]['id']);
    }
}

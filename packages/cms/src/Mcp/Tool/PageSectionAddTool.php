<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;

/** @internal */
final class PageSectionAddTool extends SectionTool
{
    public function __construct(
        Schema $schema,
        PageService $pages,
        Shapes $shapes,
        private readonly FieldValues $values,
        private readonly FieldInput $input,
    ) {
        parent::__construct($schema, $pages, $shapes);
    }

    public function name(): string
    {
        return 'page_section_add';
    }

    public function description(): string
    {
        return 'Add a section to the DRAFT of a page. type is a section type from site_schema; data holds its fields (see site_schema kind=section) — fields you leave out get the type\'s defaults, required texts get a visible placeholder you should replace. '
            . 'Put it at the end, or at position (0 = first), or after_section_id. Returns the new section id.' . self::EDITOR_NOTE;
    }

    public function inputSchema(): array
    {
        return self::schema(self::pageIdProperties() + [
            'type' => self::string('Section type key.', 64),
            'data' => ['type' => 'object', 'description' => 'Field values of the section, by key.'],
            'hidden' => ['type' => 'boolean', 'description' => 'Add it hidden (not shown on the site).'],
            'position' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Index to insert at (0 = first). Default: the end.'],
            'after_section_id' => self::string('Insert right after this section id.', 64),
        ], ['page_id', 'type']);
    }

    protected function change(array $args, McpContext $context): array
    {
        $locale = self::locale($args);
        $pageId = (string) $args['page_id'];
        [$sections, $template, $heading] = $this->open($pageId, $locale, $context);
        $type = (string) $args['type'];
        $def = $this->schema->section($type) ?? throw new McpToolException('Unknown section type "' . $type . '". Types: ' . \implode(', ', \array_keys($this->schema->sections())));
        $data = (array) ($args['data'] ?? []);
        $known = $this->knownSrcs($sections);
        self::failIf($this->input->problems($def->fieldList(), $data, $known, 'data'));

        $at = \count($sections);
        if (isset($args['after_section_id'])) {
            $at = $this->indexOf($sections, (string) $args['after_section_id']) + 1;
        } elseif (isset($args['position'])) {
            $at = \min((int) $args['position'], \count($sections));
        }
        $new = ['id' => Json::nid('s'), 'type' => $type, 'hidden' => !empty($args['hidden']), 'data' => $data + $this->values->defaults($def->fieldList())];
        \array_splice($sections, $at, 0, [$new]);

        return ['added' => true, 'sectionId' => $new['id'], 'position' => $at] + $this->save($pageId, $locale, $sections, $template, $heading, $new['id']);
    }
}

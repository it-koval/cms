<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\McpContext;

/** @internal */
final class PageSectionRemoveTool extends SectionTool
{
    public function name(): string
    {
        return 'page_section_remove';
    }

    public function description(): string
    {
        return 'Remove one section from the DRAFT of a page. The live page keeps it until the page is published, and an editor can bring it back from the history. This is the only removal this server offers (pages, records and files cannot be deleted here).' . self::EDITOR_NOTE;
    }

    public function inputSchema(): array
    {
        return self::schema(self::pageIdProperties() + ['section_id' => self::string('Section to remove.', 64)], ['page_id', 'section_id']);
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => false];
    }

    protected function change(array $args, McpContext $context): array
    {
        $locale = self::locale($args);
        $pageId = (string) $args['page_id'];
        [$sections, $template, $heading] = $this->open($pageId, $locale, $context);
        $i = $this->indexOf($sections, (string) $args['section_id']);
        $gone = $sections[$i];
        \array_splice($sections, $i, 1);

        return ['removed' => true, 'removedType' => $gone['type']] + $this->save($pageId, $locale, $sections, $template, $heading);
    }
}

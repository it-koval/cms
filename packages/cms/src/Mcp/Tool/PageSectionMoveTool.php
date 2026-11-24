<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpToolException;

/** @internal */
final class PageSectionMoveTool extends SectionTool
{
    public function name(): string
    {
        return 'page_section_move';
    }

    public function description(): string
    {
        return 'Move a section to another place in the DRAFT of a page: to a position (0 = first, counted in the final list) or right after another section.' . self::EDITOR_NOTE;
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema(self::pageIdProperties() + [
            'section_id' => self::string('Section to move.', 64),
            'position' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Index it should have afterwards (0 = first).'],
            'after_section_id' => self::string('Put it right after this section.', 64),
        ], ['page_id', 'section_id']);
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => false, 'idempotent' => true];
    }

    protected function change(array $args, McpContext $context): array
    {
        $locale = self::locale($args);
        $pageId = (string) $args['page_id'];
        if (!isset($args['position']) && !isset($args['after_section_id'])) {
            throw new McpToolException('Say where to: position or after_section_id.');
        }
        [$sections, $template, $heading] = $this->open($pageId, $locale, $context);
        $from = $this->indexOf($sections, (string) $args['section_id']);
        $moving = \array_splice($sections, $from, 1)[0];
        if (isset($args['after_section_id'])) {
            if ($args['after_section_id'] === $moving['id']) {
                throw new McpToolException('A section cannot be moved after itself.');
            }
            $to = $this->indexOf($sections, (string) $args['after_section_id']) + 1;
        } else {
            $to = \min((int) $args['position'], \count($sections));
        }
        \array_splice($sections, $to, 0, [$moving]);

        return ['moved' => true, 'position' => $to] + $this->save($pageId, $locale, $sections, $template, $heading, $moving['id']);
    }
}

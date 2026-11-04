<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\PageService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/**
 * The common part of the four section tools: they read the sections of the DRAFT, change the list and save it through PageService::saveDraft(), which is
 * what the editor's autosave does (field rights, RawFieldGuard, hooks and history all happen there). The rights are those of editing the page's template.
 *
 * @internal
 */
abstract class SectionTool extends AbstractMcpTool
{
    use FixInput;

    protected const EDITOR_NOTE = ' Sections of one page are changed one call at a time; if the page is open in the admin editor, its next autosave is refused and the editor asks the person whether to show the new version, so this change is not lost.';

    public function __construct(
        protected readonly Schema $schema,
        protected readonly PageService $pages,
        protected readonly Shapes $shapes,
    ) {
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => false, 'idempotent' => false];
    }

    /**
     * The list of sections is read, changed and written back as a whole, so one page is changed by one call at a time:
     * two parallel calls would otherwise both start from the same list and the second would drop the edit of the first.
     */
    final public function call(array $args, McpContext $context): array
    {
        return $this->pages->locked((string) $args['page_id'], fn (): array => $this->change($args, $context));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    abstract protected function change(array $args, McpContext $context): array;

    public function requiredScope(): string
    {
        return McpScopes::WRITE;
    }

    /** @return array<string, mixed> */
    protected static function pageIdProperties(): array
    {
        return ['page_id' => self::string('Page id.', 64), 'locale' => self::localeProperty()];
    }

    /**
     * Checks the right to edit the page and returns what the tools work with.
     *
     * @return array{0: list<array<string, mixed>>, 1: string, 2: string} the sections of the draft, the template key, the heading section id
     */
    protected function open(string $pageId, ?string $locale, McpContext $context): array
    {
        $template = $this->pages->find($pageId)->template;
        $context->require('pages', 'edit', $template);
        $tpl = $this->schema->template($template);
        if (null === $tpl || !$tpl->hasSections()) {
            throw new McpToolException('Pages of the template "' . $template . '" have no sections.');
        }
        $flat = $this->pages->get($pageId, $locale);
        $sections = [];
        foreach ((array) Format::plain($flat['sections'] ?? []) as $s) {
            $s['hidden'] = !empty($s['hidden']);
            $s['data'] = (array) ($s['data'] ?? []);
            $sections[] = $s;
        }

        return [$sections, $template, (string) ($this->pages->h1Id($flat, $template) ?? '')];
    }

    /**
     * Image addresses already in the sections (kept as they are, even when the file has left the library).
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return list<string>
     */
    protected function knownSrcs(array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            \array_push($out, ...FieldInput::srcsIn($s['data'] ?? [], $this->schema->section((string) ($s['type'] ?? ''))?->fieldList() ?? []));
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $sections */
    protected function indexOf(array $sections, string $sectionId): int
    {
        foreach ($sections as $i => $s) {
            if ($s['id'] === $sectionId) {
                return $i;
            }
        }

        throw new McpToolException(\sprintf('No section "%s" on this page. Sections: %s', $sectionId, \implode(', ', \array_map(static fn (array $s): string => $s['id'] . ' (' . $s['type'] . ')', $sections))));
    }

    /**
     * Saves the list and answers with the page; says so when the page heading moved to another section.
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return array<string, mixed>
     */
    protected function save(string $pageId, ?string $locale, array $sections, string $template, string $headingBefore, ?string $touched = null): array
    {
        $saved = $this->pages->saveDraft($pageId, $locale, ['sections' => \array_values($sections)]);
        $view = $this->pages->getDraft($pageId, $locale);
        $out = ['saved' => true, 'touchedSectionId' => $touched, 'page' => $this->shapes->pageView($view, false)];
        $headingAfter = (string) ($this->pages->h1Id($saved, $template) ?? '');
        if ($headingAfter !== $headingBefore) {
            $out['warning'] = 'The page heading (H1) now comes from a different section: the first visible first-screen section.';
        }
        if (!$view['published']) {
            $out['note'] = 'Saved in the draft; the page is not live.';
        } else {
            $out['note'] = 'Saved in the draft; the live page is unchanged until it is published.';
        }

        return $out;
    }
}

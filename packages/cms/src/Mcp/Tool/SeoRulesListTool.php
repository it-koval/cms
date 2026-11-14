<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\SeoRule;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Format;

/** @internal */
final class SeoRulesListTool extends AbstractMcpTool
{
    public function __construct(private readonly SeoRuleService $seo)
    {
    }

    public function name(): string
    {
        return 'seo_rules_list';
    }

    public function description(): string
    {
        return 'List the SEO rules of addresses (title, description, robots, canonical for a path or everything under it). They override the SEO of the page itself. Read-only.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'q' => self::string('Text to look for in the path, title or description.', 100),
            'limit' => self::limitProperty(50, 200),
            'offset' => self::offsetProperty(),
        ]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('set:seo', 'view');
        $q = \mb_strtolower(\trim((string) ($args['q'] ?? '')));
        $rows = [];
        foreach ($this->seo->all() as $r) {
            if ('' !== $q && !\str_contains(\mb_strtolower($r->path . ' ' . $r->title . ' ' . $r->description), $q)) {
                continue;
            }
            $rows[] = self::row($r);
        }

        return Format::page($rows, self::offset($args), self::limit($args, 50, 200));
    }

    /** @return array<string, mixed> */
    private static function row(SeoRule $r): array
    {
        return [
            'id' => $r->id, 'path' => $r->path, 'match' => $r->matchType, 'locale' => $r->locale, 'active' => $r->active,
            'title' => Format::text($r->title, 150), 'description' => Format::text($r->description, 250), 'robots' => $r->robots, 'canonical' => $r->canonical,
        ];
    }
}

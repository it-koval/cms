<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\MenuService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\Format;

/** @internal */
final class MenusGetTool extends AbstractMcpTool
{
    public function __construct(private readonly MenuService $menus)
    {
    }

    public function name(): string
    {
        return 'menus_get';
    }

    public function description(): string
    {
        return 'Get the menus of the site (header, footer …): menu areas with their items (label and where it links to, nested). Read-only: menus cannot be changed through this server.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'area' => self::string('Key of one menu area; omit for all.', 64),
            'locale' => self::localeProperty(),
        ]);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('set:menus', 'view');
        $all = $this->menus->menus(self::locale($args));
        $names = [];
        foreach ($this->menus->areas() as $a) {
            $names[$a['key']] = $a['name'];
        }
        if (isset($args['area'])) {
            if (!isset($all[$args['area']])) {
                throw new McpToolException('No such menu area: ' . $args['area'] . '. Areas: ' . \implode(', ', \array_keys($all)));
            }
            $all = [$args['area'] => $all[$args['area']]];
        }
        $out = [];
        foreach ($all as $key => $items) {
            $out[] = ['key' => $key, 'name' => $names[$key] ?? $key, 'items' => $this->items((array) $items)];
        }

        return ['areas' => $out];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $link = (array) ($i['link'] ?? []);
            $to = match ($link['kind'] ?? 'page') {
                'url' => (string) ($link['url'] ?? ''),
                'modal' => '(opens the call form)',
                default => (string) ($link['page'] ?? ''),
            };
            $row = ['label' => Format::text($i['label'] ?? '', 100), 'link' => $to];
            if (!empty($i['children']) && \is_array($i['children'])) {
                $row['children'] = $this->items($i['children']);
            }
            $out[] = $row;
        }

        return $out;
    }
}

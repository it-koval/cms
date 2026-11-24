<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\CollectionQuery;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class CollectionListTool extends AbstractMcpTool
{
    use CollectionAccess;

    public function __construct(
        private readonly Schema $schema,
        private readonly CollectionService $collections,
        private readonly CollectionQuery $query,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'collection_list';
    }

    public function description(): string
    {
        return 'List records of a collection (the collections of the site are listed in site_schema): compact rows with id, title, state and a few values, a page at a time. '
            . 'Supports search (q), filters on the fields that site_schema lists under "filters" (e.g. {"status": "draft", "date": {"from": "2026-11-01"}}) and sorting ("-date", "title"). Use collection_item_get for one record in full.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'collection' => self::string('Collection key (from site_schema).', 64),
            'q' => self::string('Search text.', 100),
            'filter' => ['type' => 'object', 'description' => 'Filters by field key; "status" is always available (draft | published). Range filters take {"from": …, "to": …}.'],
            'sort' => self::string('Field key; prefix with - for descending. Keys: see sortKeys in site_schema.', 64),
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, from 1.'],
            'per_page' => self::limitProperty(25, 100),
            'locale' => self::localeProperty(),
        ], ['collection']);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $c = $this->collectionFor($this->schema, (string) $args['collection'], 'view', $context);
        $locale = self::locale($args);
        $this->collections->loaded($c->key(), $locale);
        $result = $this->query->run($c, [
            'q' => $args['q'] ?? '', 'filter' => $args['filter'] ?? [], 'sort' => $args['sort'] ?? '', 'page' => $args['page'] ?? 1, 'perPage' => $args['per_page'] ?? 25,
        ], $locale);

        return [
            'items' => \array_map(fn (array $row): array => $this->shapes->itemRow($c, $row), $result['items']),
            'total' => $result['total'], 'page' => $result['page'], 'perPage' => $result['perPage'],
            'hasMore' => $result['page'] * $result['perPage'] < $result['total'],
        ];
    }
}

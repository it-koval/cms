<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class CollectionItemCreateTool extends AbstractMcpTool
{
    use CollectionAccess;
    use FixInput;

    public function __construct(
        private readonly Schema $schema,
        private readonly CollectionService $collections,
        private readonly FieldInput $input,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'collection_item_create';
    }

    public function description(): string
    {
        return 'Create a record in a collection as a DRAFT in the main language (not on the site until published). fields holds the values by key (see site_schema kind=collection); fields you leave out get defaults. Returns the new record with its id.';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => false, 'idempotent' => false];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema([
            'collection' => self::string('Collection key.', 64),
            'fields' => ['type' => 'object', 'description' => 'Field values by key.'],
            'parent_id' => self::string('For tree collections: id of the parent record.', 64),
        ], ['collection', 'fields']);
    }

    public function requiredScope(): string
    {
        return McpScopes::WRITE;
    }

    public function call(array $args, McpContext $context): array
    {
        $c = $this->collectionFor($this->schema, (string) $args['collection'], 'edit', $context);
        $fields = (array) $args['fields'];
        self::failIf($this->input->problems($c->fieldList(), $fields, [], 'fields'));
        $data = $fields + (isset($args['parent_id']) && $c->isTree() ? ['parentId' => (string) $args['parent_id']] : []);
        $created = $this->collections->create($c->key(), $data);

        return ['created' => true, 'note' => 'Saved as a draft; it is not on the site until published.', 'item' => $this->shapes->item($c, $created)];
    }
}

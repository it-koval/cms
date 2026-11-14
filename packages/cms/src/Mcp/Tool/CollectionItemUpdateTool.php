<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class CollectionItemUpdateTool extends AbstractMcpTool
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
        return 'collection_item_update';
    }

    public function description(): string
    {
        return 'Change fields of a record of a collection in its DRAFT (only the keys you send change; a list field is replaced as a whole). The live version is unchanged until the record is published.';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'collection' => self::string('Collection key.', 64),
            'id' => self::string('Record id.', 64),
            'fields' => ['type' => 'object', 'description' => 'Fields to change, by key.'],
            'locale' => self::localeProperty(),
        ], ['collection', 'id', 'fields']);
    }

    public function requiredScope(): string
    {
        return McpScopes::WRITE;
    }

    public function call(array $args, McpContext $context): array
    {
        return $this->collections->locked((string) $args['collection'], (string) $args['id'], fn (): array => $this->change($args, $context));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function change(array $args, McpContext $context): array
    {
        $c = $this->collectionFor($this->schema, (string) $args['collection'], 'edit', $context);
        $fields = (array) $args['fields'];
        if ([] === $fields) {
            throw new McpToolException('Nothing to change: fields is empty.');
        }
        $locale = self::locale($args);
        $current = $this->collections->get($c->key(), (string) $args['id'], $locale);
        self::failIf($this->input->problems($c->fieldList(), $fields, FieldInput::srcsIn($current['item'] ?? [], $c->fieldList()), 'fields', (array) ($current['item'] ?? [])));
        [$fields, $lost] = $this->input->keepRaw($c->fieldList(), $fields, (array) ($current['item'] ?? []), 'fields');
        self::failIf($lost);
        $this->collections->update($c->key(), (string) $args['id'], $fields, $locale);
        $view = $this->collections->get($c->key(), (string) $args['id'], $locale);

        return ['updated' => true, 'item' => $this->shapes->item($c, ($view['item'] ?? []) + ['changed' => [] !== $view['changes']])];
    }
}

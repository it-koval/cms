<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class CollectionItemPublishTool extends AbstractMcpTool
{
    use CollectionAccess;

    public function __construct(
        private readonly Schema $schema,
        private readonly CollectionService $collections,
        private readonly Shapes $shapes,
    ) {
    }

    public function name(): string
    {
        return 'collection_item_publish';
    }

    public function description(): string
    {
        return 'PUBLISH a record of a collection: its draft goes live on the site at once. Requires the publish right on the collection; required fields must be filled. Only publish what the person asked you to publish.';
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => true, 'idempotent' => true];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return self::schema(['collection' => self::string('Collection key.', 64), 'id' => self::string('Record id.', 64), 'locale' => self::localeProperty()], ['collection', 'id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::PUBLISH;
    }

    public function call(array $args, McpContext $context): array
    {
        $c = $this->collectionFor($this->schema, (string) $args['collection'], 'publish', $context);
        $locale = self::locale($args);
        $this->collections->publish($c->key(), (string) $args['id'], $locale);
        $view = $this->collections->get($c->key(), (string) $args['id'], $locale);

        return ['published' => true, 'item' => $this->shapes->item($c, ($view['item'] ?? []) + ['changed' => [] !== $view['changes']])];
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Mcp\Support\Format;
use Itk\Cms\Mcp\Support\Shapes;
use Itk\Cms\Schema\Schema;

/** @internal */
final class CollectionItemGetTool extends AbstractMcpTool
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
        return 'collection_item_get';
    }

    public function description(): string
    {
        return 'Get one record of a collection in full (the DRAFT, with every field you may see). state says whether it is live; "changes" lists what differs from the live version.';
    }

    public function annotations(): array
    {
        return ['readOnly' => true, 'destructive' => false, 'idempotent' => true];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'collection' => self::string('Collection key.', 64),
            'id' => self::string('Record id (from collection_list).', 64),
            'locale' => self::localeProperty(),
        ], ['collection', 'id']);
    }

    public function requiredScope(): string
    {
        return McpScopes::READ;
    }

    public function call(array $args, McpContext $context): array
    {
        $c = $this->collectionFor($this->schema, (string) $args['collection'], 'view', $context);
        $view = $this->collections->get($c->key(), (string) $args['id'], self::locale($args));
        if (null === $view['item']) {
            throw new McpToolException('This record has no version in the language "' . $view['locale'] . '".');
        }
        $out = $this->shapes->item($c, $view['item']);
        $out['live'] = null !== $view['published'];
        $out['changes'] = Format::changes($view['changes']);

        return $out;
    }
}

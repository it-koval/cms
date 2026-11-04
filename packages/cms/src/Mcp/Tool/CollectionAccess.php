<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;

/**
 * The rule every collection tool starts with: the collection exists, the person may see it, and has the right to do what is asked.
 *
 * @internal
 */
trait CollectionAccess
{
    private function collectionFor(Schema $schema, string $key, string $action, McpContext $context): Collection
    {
        $c = $schema->collection($key);
        if (null === $c || !$c->isVisibleTo($context->viewer())) {
            throw new McpToolException('No such collection (or you may not see it): ' . $key . '. See site_schema for the list.');
        }
        $context->require('col:' . $key, $action);

        return $c;
    }
}

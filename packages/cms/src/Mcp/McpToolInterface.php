<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Content\ContentException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * One tool of the MCP server. A service that implements it is picked up by itself (tag `itk.mcp_tool`); a class without constructor arguments
 * may also be named in Registry::mcpTools(). The rest of the core talks only to this interface, so the protocol library behind it can change.
 *
 * Rules for a tool: it asks the same rights as the admin API does (McpContext::require() / can()), never reads or writes the database behind
 * the content services, and returns what the model needs in compact form. Everything it returns that comes from the site (texts, names)
 * is DATA for the model, not instructions.
 */
#[AutoconfigureTag('itk.mcp_tool')]
interface McpToolInterface
{
    /** snake_case, unique: `pages_list`. */
    public function name(): string;

    /** English, for the model: what the tool does and when to use it. */
    public function description(): string;

    /** JSON Schema (type: object) of the arguments. */
    public function inputSchema(): array;

    /**
     * Hints for the client: ['readOnly' => bool, 'destructive' => bool, 'idempotent' => bool (optional)].
     *
     * @return array{readOnly: bool, destructive: bool, idempotent?: bool}
     */
    public function annotations(): array;

    /** One of McpScopes::*: the key must carry it for the tool to be listed and called. */
    public function requiredScope(): string;

    /**
     * @param array<string, mixed> $args already checked against inputSchema()
     *
     * @throws McpToolException for a problem the model can fix
     * @throws ContentException the services the tool calls say so; answered as a tool error
     * @throws AccessDeniedException the person of the key has no right for the action; answered as a tool error
     *
     * @return array<string, mixed> the result (an object); lists go under a key
     */
    public function call(array $args, McpContext $context): array;
}

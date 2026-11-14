<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

/**
 * A JSON-RPC level problem (unknown method, unknown tool, bad params).
 *
 * @internal
 */
final class McpProtocolError extends \RuntimeException
{
    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}

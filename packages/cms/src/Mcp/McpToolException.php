<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

/**
 * A tool could not do what was asked and says why in words the model can act on (a bad value, a missing right, a rule of the site).
 * The answer is a normal tool result with isError = true, not a protocol error: the model reads it and tries again.
 */
final class McpToolException extends \RuntimeException
{
    /** @param array<string, mixed>|list<mixed>|null $details */
    public function __construct(string $message, private readonly ?array $details = null)
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed>|list<mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }
}

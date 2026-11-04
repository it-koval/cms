<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Entity\ApiToken;

/** A key was refused. `reason`: missing | invalid | expired | revoked | blocked | two_factor. `token`: the key, when it is a real one (the audit log says whose it was). */
final class ApiTokenException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 401, public readonly ?ApiToken $token = null)
    {
        parent::__construct($message, $status);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\History;

/**
 * Through what the change that is being made comes: by hand in the admin (nothing set) or by an AI assistant (MCP). The history writes it
 * into every entry it makes meanwhile, so the history panel can say «через ИИ-ассистента (имя ключа)». Set for the time of one call, then cleared.
 *
 * @internal
 */
final class ChangeSource
{
    public const MCP = 'mcp';

    private ?string $via = null;
    private ?string $name = null;

    public function begin(string $via, string $name): void
    {
        $this->via = $via;
        $this->name = \mb_substr($name, 0, 120);
    }

    public function clear(): void
    {
        $this->via = $this->name = null;
    }

    public function via(): ?string
    {
        return $this->via;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}

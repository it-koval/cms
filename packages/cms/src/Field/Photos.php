<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A list of photos: each item is an image address (what the admin adds) or {src, alt} (the MCP tools may send it); the shape of an item is kept. */
final class Photos extends AbstractField
{
    protected function wireType(): string
    {
        return 'photos';
    }
}

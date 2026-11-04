<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

/**
 * A file a generator wants to write. The main one (the class) is never overwritten without --force; the helpers (Twig, JS) are kept when they exist.
 *
 * @internal
 */
final class GeneratedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $content,
        public readonly bool $main = false,
    ) {
    }
}

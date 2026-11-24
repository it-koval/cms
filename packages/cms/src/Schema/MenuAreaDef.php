<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

/** A place for a menu that site templates show with {{ itk_menu('key') }}. */
final class MenuAreaDef
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $depth = 1,
    ) {
    }

    /** @return array{key: string, name: string, depth: int} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'depth' => $this->depth];
    }
}

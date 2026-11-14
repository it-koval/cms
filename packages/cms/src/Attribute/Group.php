<?php

declare(strict_types=1);

namespace Itk\Cms\Attribute;

/** Puts the section of a resource class under a heading of the admin menu: #[Group('Каталог', icon: 'box', order: 20)]. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Group
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $icon = null,
        public readonly int $order = 100,
    ) {
    }
}

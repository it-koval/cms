<?php

declare(strict_types=1);

namespace Itk\Cms\Attribute;

/** Place of the section inside its group of the admin menu (smaller = higher): #[Order(10)]. A page template with it stands earlier in the list of templates, a section type in the library. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Order
{
    public function __construct(public readonly int $order)
    {
    }
}

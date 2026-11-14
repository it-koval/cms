<?php

declare(strict_types=1);

namespace Itk\Cms\Attribute;

/** Icon of the section in the admin menu: #[Icon('map')]. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Icon
{
    public function __construct(public readonly string $name)
    {
    }
}

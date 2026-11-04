<?php

declare(strict_types=1);

namespace Itk\Cms\Attribute;

/**
 * Who sees the section in the menu: a permission area ('col:tours') or a right ('col:tours:edit'). On top of the rights of the role:
 * a person without «Смотреть» for the area never sees the section anyway.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class CanSee
{
    public function __construct(public readonly string $who)
    {
    }
}

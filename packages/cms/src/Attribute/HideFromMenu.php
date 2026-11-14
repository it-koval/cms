<?php

declare(strict_types=1);

namespace Itk\Cms\Attribute;

/** The section works (API, links) but has no item in the admin menu. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HideFromMenu
{
}

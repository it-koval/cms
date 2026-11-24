<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Itk\Cms\Entity\SeoRule;

/** Rules before and after a saved change, so both the old and new address ranges can be refreshed. */
final readonly class SeoRulesChanged
{
    /** @param list<SeoRule> $rules detached snapshots, including the removed rule on deletion */
    public function __construct(public array $rules)
    {
    }
}

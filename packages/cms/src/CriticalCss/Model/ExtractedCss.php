<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * CssExtractor result for one page: crittr's critical CSS of each viewport, in viewport order (possibly '': CriticalStyles decides).
 *
 * @internal
 */
final readonly class ExtractedCss
{
    /** @param list<string> $cssPerViewport */
    public function __construct(public array $cssPerViewport)
    {
    }
}

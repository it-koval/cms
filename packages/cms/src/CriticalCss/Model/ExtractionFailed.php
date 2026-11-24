<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/**
 * CssExtractor result for one page that did not work: a short reason (crittr error, timeout, Node missing, Chrome did not start).
 *
 * @internal
 */
final readonly class ExtractionFailed
{
    public function __construct(public string $reason)
    {
    }
}

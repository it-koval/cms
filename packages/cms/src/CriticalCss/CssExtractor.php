<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Itk\Cms\CriticalCss\Model\ExtractedCss;
use Itk\Cms\CriticalCss\Model\ExtractionFailed;
use Itk\Cms\CriticalCss\Model\SourcePage;
use Itk\Cms\CriticalCss\Model\Viewport;

/**
 * The boundary to the browser: given already-rendered pages and the prepared source CSS, measure the first screen of each page in each viewport.
 * Knows nothing of entities, bases or storage.
 *
 * @internal
 */
interface CssExtractor
{
    /**
     * Results arrive as each page finishes.
     * Every page of $pages gets exactly one result, even when the process dies or times out (then ExtractionFailed for the rest).
     *
     * @param list<Viewport> $viewports sorted
     * @param non-empty-list<SourcePage> $pages
     *
     * @return iterable<string, ExtractedCss|ExtractionFailed> keyed by SourcePage::$id
     */
    public function extract(string $sourceCss, array $viewports, array $pages): iterable;
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Itk\Cms\CriticalCss\CssExtractor;
use Itk\Cms\CriticalCss\Model\ExtractedCss;
use Itk\Cms\CriticalCss\Model\ExtractionFailed;
use Itk\Cms\CriticalCss\Model\SourcePage;
use Itk\Cms\CriticalCss\Model\Viewport;

/**
 * The browser of the test site: no Node, no Chrome. Without an answer it returns one rule per viewport, naming the width;
 * a test sets `answer` to decide per page (a failure, a given CSS). `calls` is what the generator handed it.
 */
final class FakeCssExtractor implements CssExtractor
{
    /** @var list<array{css: string, viewports: list<Viewport>, pages: list<SourcePage>}> */
    public array $calls = [];
    /** @var (\Closure(SourcePage, list<Viewport>): (ExtractedCss|ExtractionFailed))|null */
    public ?\Closure $answer = null;

    public function extract(string $sourceCss, array $viewports, array $pages): iterable
    {
        $this->calls[] = ['css' => $sourceCss, 'viewports' => $viewports, 'pages' => $pages];
        foreach ($pages as $page) {
            yield $page->id => null === $this->answer
                ? new ExtractedCss(\array_map(static fn (Viewport $v): string => 'h1{width:' . $v->width . 'px}', $viewports))
                : ($this->answer)($page, $viewports);
        }
    }

    /** Between tests; not reset(), which the container would also call between requests. */
    public function forget(): void
    {
        $this->calls = [];
        $this->answer = null;
    }
}

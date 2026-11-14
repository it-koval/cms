<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss\Model;

/** One page as the generator hands it to the extractor: rendered by PHP (PageRenderer::renderSource), never fetched over HTTP. */
final readonly class SourcePage
{
    public function __construct(
        /** the PageTranslation id: the key of the result */
        public string $id,
        /** the page's own path (/about/team): the Node server answers the HTML there, so relative links and location.pathname are the real ones */
        public string $path,
        public string $html,
    ) {
    }
}

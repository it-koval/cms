<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\CriticalCss\Model\Basis;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\CriticalCss\Model\Freshness;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Rendering\PageValidators;

/**
 * Critical CSS of live pages, request side (the live render): which basis a page wants and whether its row is good for it.
 * It reads itk_critical_css and never writes it; CriticalCssGenerator (worker side) is the one writer.
 *
 * @internal
 */
final class CriticalCssService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Stylesheets $stylesheets,
        private readonly PageValidators $validators,
    ) {
    }

    /**
     * For PageRenderer::renderLive(): the styles to inline, or null (then the layout links the stylesheets the plain, blocking way).
     * Never throws: a broken database costs the visitor only the inlining.
     */
    public function forLiveRender(PageTranslation $t): ?CriticalStyles
    {
        if (!$this->stylesheets->enabled || !$t->isLive()) {
            return null;
        }
        try {
            $row = $this->em->find(CriticalCss::class, $t->id);
            $state = Freshness::of($row, $this->wanted($t));
        } catch (\Throwable) {
            return null;
        }

        return Freshness::Ready === $state && null !== $row && null !== $row->styles && [] !== $row->styles ? CriticalStyles::fromStored($row->styles) : null;
    }

    /** The basis the page wants right now (the snapshot, the build and the stylesheet stamp). The generator stamps rows with it. */
    public function wanted(PageTranslation $t): Basis
    {
        return Basis::of($t->published ?? [], $this->validators->build(), $this->stylesheets->stamp());
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Itk\Cms\Rendering\RenderState;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * itk_stylesheets(): the site's stylesheets (itk_cms.critical_css.stylesheets) for the <head> of the layout, on ANY page of the site.
 *
 * With ready critical CSS (only a live CMS page drawn by PageRenderer::renderLive whose row is Ready for the current basis or Stale with ready styles, see RenderState::$critical):
 *     <style>critical</style>   one block (the union of the viewports); a row of an older version may hold one <style media="…"> per range
 *     <link rel="stylesheet" href="/css/site.css?v=…" media="print" onload="this.media='all'">
 *     <noscript><link rel="stylesheet" href="/css/site.css?v=…"></noscript>
 * Otherwise (feature off, Missing / Failed, admin preview, a site controller, an error page, the generator's own render):
 *     <link rel="stylesheet" href="/css/site.css?v=…">
 * The decision is PageRenderer's, not this function's: it prints what RenderState holds, so it has no way to inline in preview.
 * `</` inside the CSS is written `<\/` (a valid CSS escape), so a stylesheet can never close the <style> early.
 * With {nonce: x} the <style> tags and the swap link carry nonce="x" for a CSP site.
 *
 * @internal
 */
final class StylesheetsRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly RenderState $state,
        private readonly Stylesheets $stylesheets,
    ) {
    }

    /** @param array{nonce?: string|null} $options */
    public function stylesheets(array $options = []): string
    {
        $nonce = isset($options['nonce']) && '' !== $options['nonce'] ? ' nonce="' . self::attr((string) $options['nonce']) . '"' : '';
        $links = $this->stylesheets->links();
        $critical = $this->state->critical;
        $key = $this->state->source ? 'href' : 'versioned';
        if (null === $critical) {
            return \implode('', \array_map(static fn (array $l): string => '<link rel="stylesheet" href="' . self::attr($l[$key]) . '">', $links));
        }
        $html = '';
        foreach ($critical->blocks as $block) {
            $media = null === $block['media'] ? '' : ' media="' . self::attr($block['media']) . '"';
            $html .= '<style' . $nonce . $media . '>' . \str_replace('</', '<\/', $block['css']) . '</style>';
        }
        foreach ($links as $l) {
            $href = self::attr($l[$key]);
            $html .= '<link rel="stylesheet" href="' . $href . '"' . $nonce . ' media="print" onload="this.media=\'all\'">';
            $html .= '<noscript><link rel="stylesheet" href="' . $href . '"></noscript>';
        }

        return $html;
    }

    private static function attr(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}

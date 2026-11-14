<?php

declare(strict_types=1);

namespace Itk\Cms\Twig;

use Itk\Cms\Admin\Theme\AdminFont;
use Itk\Cms\CriticalCss\StylesheetsRuntime;
use Itk\Cms\I18n\I18n;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Registers the ITK CMS Twig functions; ItkRuntime holds the code (see there for the list), itk_seo() lives in SeoRuntime, itk_admin_font() (the font of the sign-in pages of the admin) in Admin\Theme\AdminFont.
 *
 * @internal
 */
final class ItkExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('itk_field', [ItkRuntime::class, 'field'], ['is_safe' => ['html']]),
            new TwigFunction('itk_field_attrs', [ItkRuntime::class, 'fieldAttrs'], ['is_safe' => ['html']]),
            new TwigFunction('itk_section_attrs', [ItkRuntime::class, 'sectionAttrs'], ['is_safe' => ['html']]),
            new TwigFunction('itk_section_class', [ItkRuntime::class, 'sectionClass']),
            new TwigFunction('render_sections', [ItkRuntime::class, 'renderSections'], ['needs_environment' => true, 'needs_context' => true, 'is_safe' => ['html']]),
            new TwigFunction('menu', [ItkRuntime::class, 'menu']),
            new TwigFunction('settings', [ItkRuntime::class, 'setting']),
            new TwigFunction('media_url', [ItkRuntime::class, 'mediaUrl']),
            new TwigFunction('media_srcset', [ItkRuntime::class, 'mediaSrcset']),
            new TwigFunction('media_width', [ItkRuntime::class, 'mediaWidth']),
            new TwigFunction('media_ratio', [ItkRuntime::class, 'mediaRatio']),
            new TwigFunction('itk_img', [ItkRuntime::class, 'img'], ['is_safe' => ['html']]),
            new TwigFunction('page_url', [ItkRuntime::class, 'pageUrl']),
            new TwigFunction('listing', [ItkRuntime::class, 'listingOf']),
            new TwigFunction('itk_collection', [ItkRuntime::class, 'collection']),
            new TwigFunction('itk_pages', [ItkRuntime::class, 'pages']),
            new TwigFunction('itk_page', [ItkRuntime::class, 'page']),
            new TwigFunction('itk_is_preview', [ItkRuntime::class, 'isPreview']),
            new TwigFunction('itk_cache', [ItkRuntime::class, 'cache']),
            new TwigFunction('itk_admin_font', [AdminFont::class, 'css'], ['is_safe' => ['html']]),
            new TwigFunction('itk_stylesheets', [StylesheetsRuntime::class, 'stylesheets'], ['is_safe' => ['html']]),
            new TwigFunction('itk_seo', [SeoRuntime::class, 'seo'], ['needs_context' => true]),
            new TwigFunction('itk_t', [I18n::class, 't']),
            new TwigFunction('itk_locale', [I18n::class, 'locale']),
        ];
    }
}

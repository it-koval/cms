<?php

declare(strict_types=1);

namespace Itk\Cms\Twig;

use Itk\Cms\Content\SeoResolver;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * itk_seo([defaults]): the meta tags of the page being rendered, for ANY address (a CMS page or a route of the site).
 * A site layout prints them: <title>{{ seo.title }}</title>, <meta name="description" ...>, and so on.
 *
 *   {% set seo = itk_seo({ title: 'Мой сайт', robots: 'index,follow' }) %}
 *
 * Returns { title, description, robots, canonical, ogImage, rule }:
 *   title        SEO rule of the address, else the page's SEO title, else the page title, else defaults.title
 *   description  rule, else the page's, else defaults.description
 *   robots       rule, else defaults.robots ('' = print nothing, or the site's own default)
 *   canonical    rule, else defaults.canonical; a full address or '' (then the layout keeps its own logic)
 *   ogImage      rule, else defaults.ogImage; a full address or ''
 *   rule         id of the SEO rule that took part, or null
 * A rule only overrides the fields it fills in. On an error page the rules are not applied.
 *
 * @internal
 */
final class SeoRuntime implements RuntimeExtensionInterface
{
    public function __construct(private readonly SeoResolver $seo)
    {
    }

    /**
     * @param array<string, mixed> $context the Twig context: `page` is read from it when there is one
     * @param array<string, mixed> $defaults
     *
     * @return array{title: string, description: string, robots: string, canonical: string, ogImage: string, rule: string|null}
     */
    public function seo(array $context, array $defaults = []): array
    {
        $page = \is_array($context['page'] ?? null) ? $context['page'] : [];
        $seo = \is_array($page['seo'] ?? null) ? $page['seo'] : [];
        if (!\array_key_exists('rule', $seo)) { // not rendered by PageRenderer: the rule of the requested address is not laid over it yet
            $seo = $this->seo->apply($seo, $this->seo->forRequest());
        }

        return $this->seo->meta($seo, \is_scalar($page['title'] ?? null) ? (string) $page['title'] : '', $defaults);
    }
}

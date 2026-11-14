<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\SeoRule;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\PageTranslationRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * «Проверить адрес» of the SEO screen: what the site answers at an address (a page of the site, a CMS page, a redirect, nothing),
 * which SEO rules cover it and where each meta tag comes from (the rule, the page itself or nobody: then the site's template decides).
 * Follows the order of the real site: a route of the site wins over the catch-all, which tries redirects first, then pages.
 *
 * @internal
 */
final class SeoAddressCheck
{
    /** The catch-all route of Itk\Cms\Controller\Site\PageController. */
    private const CATCH_ALL = 'itk_page';

    private const TAGS = ['title', 'description', 'robots', 'canonical', 'ogImage'];

    public function __construct(
        private readonly SeoResolver $resolver,
        private readonly RedirectService $redirects,
        private readonly PageTranslationRepository $translations,
        private readonly RouterInterface $router,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     answer: array{kind: 'route'|'redirect'|'page'|'missing', label: string, status: int, to?: string, page?: array<string, mixed>},
     *     rules: list<array<string, mixed>>,
     *     meta: array<string, array{value: string, source: 'rule'|'page'|'title'|'none'}>|null
     * }
     */
    public function check(string $address, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $path = SeoRuleService::normalizePath($address);
        [$pathOnly, $queryString] = \array_pad(\explode('?', $path, 2), 2, '');
        \parse_str($queryString, $query);

        $matches = $this->resolver->matches($pathOnly, $query, $locale);
        $rules = [];
        foreach ($matches as $i => $rule) {
            $rules[] = ['id' => $rule->id, 'path' => $rule->path, 'match' => $rule->matchType, 'locale' => $rule->locale, 'winner' => 0 === $i];
        }
        $route = $this->routeOf($pathOnly);
        if (null !== $route && self::CATCH_ALL !== $route) {
            return $this->result($path, $rules, ['kind' => 'route', 'status' => 200, 'label' => I18n::t('content.seo_check_route')], $this->sources($matches[0] ?? null, [], ''));
        }
        $redirect = $this->redirects->lookup($pathOnly);
        if (null !== $redirect) {
            return $this->result($path, $rules, [
                'kind' => 'redirect', 'status' => $redirect->code, 'to' => $redirect->toPath,
                'label' => I18n::t('content.seo_check_redirect', ['to' => $redirect->toPath, 'code' => $redirect->code]),
            ], null);
        }
        $t = $this->translations->findLive($locale, $pathOnly);
        if (null === $t) {
            return $this->result($path, $rules, ['kind' => 'missing', 'status' => 404, 'label' => I18n::t('content.seo_check_missing')], null);
        }
        $snapshot = $t->published ?? [];
        $title = (string) ($snapshot['title'] ?? '');

        return $this->result($path, $rules, [
            'kind' => 'page', 'status' => 200, 'label' => I18n::t('content.seo_check_page', ['title' => $title]),
            'page' => ['id' => $t->page->id, 'title' => $title, 'template' => $t->page->template, 'url' => (string) ($snapshot['url'] ?? $pathOnly)],
        ], $this->sources($matches[0] ?? null, (array) ($snapshot['seo'] ?? []), $title));
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed> $answer
     * @param array<string, array{value: string, source: 'rule'|'page'|'title'|'none'}>|null $meta
     *
     * @return array<string, mixed>
     */
    private function result(string $path, array $rules, array $answer, ?array $meta): array
    {
        return ['path' => $path, 'answer' => $answer, 'rules' => $rules, 'meta' => $meta];
    }

    private function routeOf(string $path): ?string
    {
        try {
            return (string) ($this->router->match($path)['_route'] ?? '');
        } catch (RoutingException) {
            return null; // no route at all
        }
    }

    /**
     * @param array<string, mixed> $pageSeo
     *
     * @return array<string, array{value: string, source: 'rule'|'page'|'title'|'none'}>
     */
    private function sources(?SeoRule $rule, array $pageSeo, string $pageTitle): array
    {
        $final = $this->resolver->meta($this->resolver->apply($pageSeo, $rule), $pageTitle);
        $meta = [];
        foreach (self::TAGS as $tag) {
            $source = match (true) {
                null !== $rule && '' !== $rule->{$tag} => 'rule',
                \in_array($tag, ['title', 'description'], true) && '' !== (string) ($pageSeo[$tag] ?? '') => 'page',
                'title' === $tag && '' !== $pageTitle => 'title',
                default => 'none',
            };
            $meta[$tag] = ['value' => $final[$tag], 'source' => $source];
        }

        return $meta;
    }
}

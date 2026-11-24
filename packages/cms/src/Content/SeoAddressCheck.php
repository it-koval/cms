<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\SeoRule;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Support\Url;
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
        private readonly ContentLocales $locales,
        private readonly ?PublicUrl $publicUrl = null,
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
        $path = SeoRuleService::normalizePath($address);
        [$requestedPath, $requestedQuery] = \array_pad(\explode('?', \trim($address), 2), 2, '');
        \parse_str($requestedQuery, $query);
        $decodedPath = \rawurldecode($requestedPath);
        $pathOnly = Url::fromPath($decodedPath);
        $pageLocale = $this->locales->fromPath($pathOnly);
        $route = $this->routeOf($requestedPath);
        $routeName = $route['_route'] ?? null;
        $ownRoute = null !== $routeName && self::CATCH_ALL !== $routeName;
        $redirect = $ownRoute ? null : $this->redirects->lookup($pathOnly, $requestedQuery);
        $t = $ownRoute || null !== $redirect ? null : $this->translations->findLiveAtPath($pageLocale, $pathOnly, $pageLocale !== $this->locales->main());
        $snapshot = $t->published ?? [];
        $stored = (string) ($snapshot['url'] ?? $pathOnly);
        $applied = $this->resolver->best($stored, $query, $pageLocale);

        $matches = $this->resolver->matches($pathOnly, $query, $locale ?? $pageLocale);
        $rules = [];
        foreach ($matches as $rule) {
            $rules[] = ['id' => $rule->id, 'path' => $rule->path, 'match' => $rule->matchType, 'locale' => $rule->locale, 'winner' => $rule->id === $applied?->id];
        }
        if ($ownRoute) {
            return $this->result($path, $rules, ['kind' => 'route', 'status' => 200, 'label' => I18n::t('content.seo_check_route')], $this->sources($applied, [], ''));
        }
        if (null !== $redirect) {
            return $this->result($path, $rules, [
                'kind' => 'redirect', 'status' => $redirect->code, 'to' => $redirect->toPath,
                'label' => I18n::t('content.seo_check_redirect', ['to' => $redirect->toPath, 'code' => $redirect->code]),
            ], null);
        }
        if (null === $t) {
            return $this->result($path, $rules, ['kind' => 'missing', 'status' => 404, 'label' => I18n::t('content.seo_check_missing')], null);
        }
        $to = Url::canonicalRedirect((string) ($route['path'] ?? \substr($decodedPath, 1)), $requestedPath, $stored, $requestedQuery);
        if (null !== $to) {
            return $this->result($path, $rules, [
                'kind' => 'redirect', 'status' => 301, 'to' => $to,
                'label' => I18n::t('content.seo_check_redirect', ['to' => $to, 'code' => 301]),
            ], null);
        }
        $title = (string) ($snapshot['title'] ?? '');
        $base = $this->locales->isMultilingual() ? $this->publicUrl?->seoBase() : null;
        $canonical = null === $base ? '' : $base . Url::encodePath(Url::spelling($stored));

        return $this->result($path, $rules, [
            'kind' => 'page', 'status' => 200, 'label' => I18n::t('content.seo_check_page', ['title' => $title]),
            'page' => ['id' => $t->page->id, 'title' => $title, 'template' => $t->page->template, 'url' => $stored],
        ], $this->sources($applied, (array) ($snapshot['seo'] ?? []), $title, $canonical));
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

    /** @return array<string, mixed>|null */
    private function routeOf(string $path): ?array
    {
        try {
            return $this->router->match($path);
        } catch (RoutingException) {
            return null; // no route at all
        }
    }

    /**
     * @param array<string, mixed> $pageSeo
     *
     * @return array<string, array{value: string, source: 'rule'|'page'|'title'|'none'}>
     */
    private function sources(?SeoRule $rule, array $pageSeo, string $pageTitle, string $canonical = ''): array
    {
        $final = $this->resolver->meta($this->resolver->apply($pageSeo, $rule), $pageTitle);
        if ('' === $final['canonical']) {
            $final['canonical'] = $canonical;
        }
        $meta = [];
        foreach (self::TAGS as $tag) {
            $source = match (true) {
                null !== $rule && '' !== $rule->{$tag} => 'rule',
                \is_scalar($pageSeo[$tag] ?? null) && '' !== (string) $pageSeo[$tag] => 'page',
                'canonical' === $tag && '' !== $canonical => 'page',
                'title' === $tag && '' !== $pageTitle => 'title',
                default => 'none',
            };
            $meta[$tag] = ['value' => $final[$tag], 'source' => $source];
        }

        return $meta;
    }
}

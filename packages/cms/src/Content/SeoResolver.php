<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\SeoRule;
use Itk\Cms\Repository\SeoRuleRepository;
use Itk\Cms\Support\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which SEO rule stands for an address and what the meta tags of the address end up as.
 *
 * A rule covers an address when its path is the address ('exact') or a parent of it ('prefix', whole segments: "/blog" covers "/blog/a",
 * not "/blogger"), the language fits (a rule without a language fits every one) and every parameter of its query string is in the
 * request with the same value (other parameters, such as utm_source, are ignored). Of several rules the most specific wins:
 * more query parameters, then 'exact' before 'prefix', then the longer path, then the rule of the language itself, then the newer one.
 *
 * A rule only overrides the fields it fills in: title and description lay over the page's own SEO, robots / canonical / ogImage are new.
 * Used by PageRenderer (the page's `seo` already carries the rule) and by the Twig function itk_seo() (any address).
 */
final class SeoResolver implements ResetInterface
{
    /** @var list<SeoRule>|null */
    private ?array $active = null;

    public function __construct(
        private readonly SeoRuleRepository $rules,
        private readonly RequestStack $requests,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /** Forget the rules read so far (a rule was saved, or the next request of a long-running process). */
    public function reset(): void
    {
        $this->active = null;
    }

    /**
     * Every active rule that covers the address, the winner first.
     *
     * @param array<array-key, mixed> $query the query string of the request
     *
     * @return list<SeoRule>
     */
    public function matches(string $path, array $query = [], ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $hits = [];
        foreach ($this->active() as $rule) {
            if (!self::covers($rule, $path, $query, $locale)) {
                continue;
            }
            $own = Url::key($rule->pathOnly());
            $params = $rule->queryParams();
            $hits[] = [$rule, [\count($params), SeoRule::EXACT === $rule->matchType ? 1 : 0, \strlen($own), $rule->locale === $locale ? 1 : 0, $rule->updatedAt->getTimestamp()]];
        }
        \usort($hits, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: \strcmp($b[0]->id, $a[0]->id));

        return \array_map(static fn (array $h): SeoRule => $h[0], $hits);
    }

    /** The same address matching is used when a changed rule invalidates derived content. @param array<array-key, mixed> $query */
    public static function covers(SeoRule $rule, string $path, array $query, string $locale): bool
    {
        if (!$rule->active || (null !== $rule->locale && '' !== $rule->locale && $rule->locale !== $locale)) {
            return false;
        }
        $key = Url::key($path);
        $own = Url::key($rule->pathOnly());
        $covers = SeoRule::EXACT === $rule->matchType
            ? $key === $own
            : ('/' === $own || $key === $own || \str_starts_with($key, $own . '/'));
        if (!$covers) {
            return false;
        }
        foreach ($rule->queryParams() as $name => $value) {
            if (!\array_key_exists($name, $query) || !self::same($query[$name], $value)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $query */
    public function best(string $path, array $query = [], ?string $locale = null): ?SeoRule
    {
        return $this->matches($path, $query, $locale)[0] ?? null;
    }

    /** The rule of the address the current request asks for (null on an error page: the rules are for addresses that answer). */
    public function forRequest(?Request $request = null, ?string $locale = null): ?SeoRule
    {
        $request ??= $this->requests->getCurrentRequest();
        if (null === $request || $request->attributes->has('exception')) {
            return null;
        }

        return $this->best($request->getPathInfo(), $request->query->all(), $locale ?? (string) ($request->attributes->get('_locale') ?? $this->mainLocale));
    }

    /**
     * The SEO of a page with the rule laid over it. The result always has `robots`, `canonical`, `ogImage` ('' when no rule sets them)
     * and `rule` (the id of the rule or null): templates can tell that the rule has been applied already.
     *
     * @param array<string, mixed> $pageSeo { title, description } of the page
     *
     * @return array<string, mixed>
     */
    public function apply(array $pageSeo, ?SeoRule $rule): array
    {
        $seo = $pageSeo + ['title' => '', 'description' => ''];
        $seo += ['robots' => '', 'canonical' => '', 'ogImage' => '', 'rule' => null];
        if (null === $rule) {
            return $seo;
        }
        foreach (['title', 'description', 'robots', 'canonical', 'ogImage'] as $k) {
            if ('' !== $rule->{$k}) {
                $seo[$k] = $rule->{$k};
            }
        }
        $seo['rule'] = $rule->id;

        return $seo;
    }

    /**
     * What a template prints: the SEO of apply() with the fallbacks. title: SEO, then the page title, then defaults.title;
     * description, robots, canonical, ogImage: SEO, then the same key of $defaults. canonical and ogImage become full addresses.
     *
     * @param array<string, mixed> $seo result of apply()
     * @param array<string, mixed> $defaults what the site says when nothing else does
     *
     * @return array{title: string, description: string, robots: string, canonical: string, ogImage: string, rule: string|null}
     */
    public function meta(array $seo, string $pageTitle = '', array $defaults = [], ?Request $request = null): array
    {
        $pick = static function (string ...$candidates): string {
            foreach ($candidates as $c) {
                if ('' !== $c) {
                    return $c;
                }
            }

            return '';
        };
        $text = static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '';
        $request ??= $this->requests->getCurrentRequest();
        $full = static function (string $url) use ($request): string {
            if (null !== $request && \str_starts_with($url, '/')) {
                return $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $url;
            }

            return $url;
        };

        return [
            'title' => $pick($text($seo['title'] ?? ''), $pageTitle, $text($defaults['title'] ?? '')),
            'description' => $pick($text($seo['description'] ?? ''), $text($defaults['description'] ?? '')),
            'robots' => $pick($text($seo['robots'] ?? ''), $text($defaults['robots'] ?? '')),
            'canonical' => $full($pick($text($seo['canonical'] ?? ''), $text($defaults['canonical'] ?? ''))),
            'ogImage' => $full($pick($text($seo['ogImage'] ?? ''), $text($defaults['ogImage'] ?? ''))),
            'rule' => isset($seo['rule']) ? (string) $seo['rule'] : null,
        ];
    }

    /** @return list<SeoRule> */
    private function active(): array
    {
        return $this->active ??= $this->rules->findActive();
    }

    private static function same(mixed $requested, mixed $wanted): bool
    {
        if (\is_array($wanted) || \is_array($requested)) {
            return \is_array($wanted) && \is_array($requested) && \json_encode($wanted) === \json_encode($requested);
        }

        return (string) $requested === (string) $wanted;
    }
}

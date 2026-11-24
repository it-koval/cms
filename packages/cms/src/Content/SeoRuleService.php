<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\SeoRule;
use Itk\Cms\Event\SeoRulesChanged;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SeoRuleRepository;
use Itk\Cms\Support\Url;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * SEO rules of addresses (Настройки → SEO → Правила адресов): the list, create, change, remove. Saved at once, there is no publish step.
 * The rules themselves are applied by SeoResolver.
 *
 * @internal
 */
final class SeoRuleService
{
    /** What «Индексация» may say. Empty = the page decides (the site's default is «index,follow»). */
    public const ROBOTS = ['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'];

    private const FIELDS = ['title' => 255, 'description' => 500, 'canonical' => 512, 'ogImage' => 512];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeoRuleRepository $rules,
        private readonly SeoResolver $resolver,
        private readonly ContentLocales $contentLocales,
        private readonly ?SitemapBuilder $sitemap = null,
        private readonly ?CrawlerFiles $crawlerFiles = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    /** @return list<SeoRule> newest first */
    public function all(): array
    {
        return $this->rules->findAllNewestFirst();
    }

    public function find(string $id): SeoRule
    {
        return $this->rules->find($id) ?? throw ContentException::notFound(I18n::t('content.seo_rule_not_found'));
    }

    /**
     * @param array<string, mixed> $data path, match?, locale?, title?, description?, robots?, canonical?, ogImage?, active?
     */
    public function create(array $data): SeoRule
    {
        $rule = new SeoRule('/');
        $this->fill($rule, $data + ['path' => '']);
        $this->em->persist($rule);
        $this->em->flush();
        $this->resolver->reset();
        $this->forgetCrawlerFiles(); // «noindex» of a rule changes the sitemap and llms.txt
        $this->events?->dispatch(new SeoRulesChanged([clone $rule]));

        return $rule;
    }

    /**
     * @param array<string, mixed> $patch any of the keys of create()
     */
    public function update(string $id, array $patch): SeoRule
    {
        $rule = $this->find($id);
        $before = clone $rule;
        $this->fill($rule, $patch);
        $rule->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->resolver->reset();
        $this->forgetCrawlerFiles();
        $this->events?->dispatch(new SeoRulesChanged([$before, clone $rule]));

        return $rule;
    }

    public function remove(string $id): void
    {
        $rule = clone $this->find($id);
        $this->em->remove($this->find($id));
        $this->em->flush();
        $this->resolver->reset();
        $this->forgetCrawlerFiles();
        $this->events?->dispatch(new SeoRulesChanged([$rule]));
    }

    /**
     * The comparable form of an address with an optional query: "/Catalog/?b=2&a=1" -> "/catalog?a=1&b=2".
     *
     * @throws ContentException an address that cannot be a rule
     */
    public static function normalizePath(string $raw): string
    {
        $raw = \trim($raw);
        if ('' === $raw) {
            throw ContentException::invalid(I18n::t('content.seo_path_required'), ['path' => I18n::t('content.seo_path_required_hint')]);
        }
        if (\preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) {
            throw ContentException::invalid(I18n::t('content.seo_path_no_domain'), ['path' => I18n::t('content.seo_path_no_domain_hint')]);
        }
        if (!\str_starts_with($raw, '/') || \str_starts_with($raw, '//')) {
            throw ContentException::invalid(I18n::t('content.seo_path_slash'), ['path' => I18n::t('content.seo_path_slash_hint')]);
        }
        if (\preg_match('/\s|#/u', $raw)) {
            throw ContentException::invalid(I18n::t('content.seo_path_chars'), ['path' => I18n::t('content.seo_path_chars')]);
        }
        if (\mb_strlen($raw) > 500) {
            throw ContentException::invalid(I18n::t('content.seo_path_long'), ['path' => I18n::t('content.seo_path_long')]);
        }
        [$path, $query] = \array_pad(\explode('?', $raw, 2), 2, '');
        $path = self::requestPathKey($path);
        \parse_str($query, $params);
        self::sortParams($params);
        $query = \http_build_query($params, '', '&', \PHP_QUERY_RFC3986);

        return '' === $query ? $path : $path . '?' . $query;
    }

    /** A stored path is already in its canonical spelling. */
    public static function pathKey(string $path): string
    {
        return Url::key(Url::fromPath($path));
    }

    /** Decode HTTP input once without turning an escaped delimiter into path structure. */
    public static function requestPathKey(string $path): string
    {
        $decoded = \preg_replace_callback('/(?:%[a-f0-9]{2})+/i', static function (array $run): string {
            // A legacy rule may contain malformed UTF-8 escapes. Keep those bytes escaped instead of
            // letting case folding replace them and silently change the address (or fail a PostgreSQL write).
            $validUtf8 = \mb_check_encoding(\rawurldecode($run[0]), 'UTF-8');

            return \preg_replace_callback('/%([a-f0-9]{2})/i', static function (array $match) use ($validUtf8): string {
                $byte = (int) \hexdec($match[1]);
                $char = \chr($byte);
                if ($byte >= 128) {
                    return $validUtf8 ? $char : $match[0];
                }

                return \ctype_alnum($char) || \str_contains('-._~', $char) ? $char : $match[0];
            }, $run[0]) ?? $run[0];
        }, $path);

        return self::pathKey($decoded ?? $path);
    }

    /** @param array<array-key, mixed> $params */
    private static function sortParams(array &$params): void
    {
        \ksort($params);
        foreach ($params as &$v) {
            if (\is_array($v)) {
                self::sortParams($v);
            }
        }
    }

    /** The sitemap and the standard llms.txt are built from the pages the rules let through. */
    private function forgetCrawlerFiles(): void
    {
        $this->sitemap?->forget();
        $this->crawlerFiles?->forget();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fill(SeoRule $rule, array $data): void
    {
        $errors = [];
        $path = $rule->path;
        if (\array_key_exists('path', $data)) {
            try {
                $path = self::normalizePath((string) $data['path']);
            } catch (ContentException $e) {
                $errors['path'] = (string) (\is_array($e->details()) ? ($e->details()['path'] ?? $e->getMessage()) : $e->getMessage());
            }
        }
        $match = \array_key_exists('match', $data) ? (string) $data['match'] : $rule->matchType;
        if (!\in_array($match, [SeoRule::EXACT, SeoRule::PREFIX], true)) {
            $errors['match'] = I18n::t('content.seo_match_choose');
        } elseif (SeoRule::PREFIX === $match && \str_contains($path, '?')) {
            $errors['path'] = I18n::t('content.seo_prefix_query');
        }
        $locale = $rule->locale;
        if (\array_key_exists('locale', $data)) {
            $input = $data['locale'];
            if (null === $input || '' === $input) {
                $locale = null;
            } elseif (!\is_string($input) || (!$this->contentLocales->isConfigured() && 1 !== \preg_match('/^[A-Za-z]{2,8}\z/', $input))) {
                $errors['locale'] = I18n::t('content.seo_locale_unknown');
            } else {
                try {
                    $locale = $this->contentLocales->assertUsable($input);
                } catch (ContentException) {
                    $errors['locale'] = I18n::t('content.seo_locale_unknown');
                }
            }
        }
        $values = [];
        foreach (self::FIELDS as $key => $max) {
            $values[$key] = \array_key_exists($key, $data) ? \trim((string) ($data[$key] ?? '')) : $rule->{$key};
            if (\mb_strlen($values[$key]) > $max) {
                $errors[$key] = I18n::t('content.seo_too_long', ['max' => $max]);
            }
        }
        foreach (['canonical' => 'content.seo_canonical_invalid', 'ogImage' => 'content.seo_image_invalid'] as $key => $message) {
            if ('' !== $values[$key] && !\preg_match('~^(/(?![/\\\\])|https?://)\S*$~i', $values[$key])) {
                $errors[$key] = I18n::t($message);
            }
        }
        $robots = \array_key_exists('robots', $data) ? \trim((string) ($data['robots'] ?? '')) : $rule->robots;
        if ('' !== $robots && !\in_array($robots, self::ROBOTS, true)) {
            $errors['robots'] = I18n::t('content.seo_robots_choose');
        }
        if ([] === $errors && '' === $values['title'] . $values['description'] . $values['canonical'] . $values['ogImage'] . $robots) {
            $errors['title'] = I18n::t('content.seo_fill_one');
        }
        if ([] === $errors) {
            $same = $this->rules->findSame($path, $match, $locale);
            if (null !== $same && $same->id !== $rule->id) {
                $errors['path'] = I18n::t('content.seo_rule_exists');
            }
        }
        if ([] !== $errors) {
            throw ContentException::invalid((string) \reset($errors), $errors);
        }

        $rule->path = $path;
        $rule->matchType = $match;
        $rule->locale = $locale;
        $rule->title = $values['title'];
        $rule->description = $values['description'];
        $rule->canonical = $values['canonical'];
        $rule->ogImage = $values['ogImage'];
        $rule->robots = $robots;
        if (\array_key_exists('active', $data)) {
            $rule->active = (bool) $data['active'];
        }
    }
}

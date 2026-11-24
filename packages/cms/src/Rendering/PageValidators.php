<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Itk\Cms\Analytics\AnalyticsAsset;
use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Doctrine\JsonPath;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Security\CspNonce;
use Itk\Cms\Support\Json;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * ETag and Last-Modified of a live page, worked out by the database without drawing the page (the 304 of PageController), and the fingerprint they are made of, which is also the key of PageCache.
 * The fingerprint is a hash of everything a page can show: the page itself (its publication date and the snapshot), the live pages of the language (menus show only live links, lists show pages),
 * the place of those pages in the tree (a list shows pages in that order), the settings, the menus, the live records of the collections, the frames saved for pictures (they decide the address of a picture),
 * the SEO rules, the live language versions of that same page, the critical CSS of the page and the stamp of the stylesheets (both are what itk_stylesheets() prints), the query of the request,
 * the analytics start mode and loader version, the build of the container (a deploy that clears the cache changes it) and the day (UTC): a page that shows what is on right now
 * (the tours that have not started yet) is out of date by the evening, so no tag lives longer than a day. The tag is the start of the fingerprint.
 * Drafts stay out of it: the tag is public, so it must not tell when somebody edits a page or a record that is not on the site, and an autosave must not cost the visitors their 304.
 * A live record counts by the time of its publication and not by its snapshot: reading the snapshots of a big collection on every request is ten times the work. Records published before that time was kept count by updatedAt.
 * That is one query of a few sums over tables the core reads whole anyway. The validators are only for a visitor without a session; none in debug (a changed template must show at once) and with the option off.
 * The date is the publication of the page or of the newest live page; a request that has a date but no tag is always answered in full, because a date cannot tell that a setting changed.
 *
 * @internal
 */
final class PageValidators
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire(param: 'itk_cms.conditional_responses')]
        private readonly bool $enabled,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
        #[Autowire(param: 'kernel.container_class')]
        private readonly string $containerClass,
        private readonly ClockInterface $clock,
        private readonly Stylesheets $stylesheets,
        private readonly ?ContentLocales $locales = null,
        #[Autowire(param: 'itk_cms.analytics.start')]
        private readonly string $analyticsStart = AnalyticsConfig::AUTO,
        private readonly AnalyticsAsset $analyticsAsset = new AnalyticsAsset(),
    ) {
    }

    /** @var array{0: string, 1: \DateTimeImmutable}|null the fingerprint last worked out and its date: the date comes from the same query, so a caller that already has the fingerprint does not ask again */
    private ?array $last = null;

    /**
     * The hash of everything the page can show, sha1 of the parts listed above; null when the page is not live or the database answers nothing.
     * It does not look at the rules of the mode (option, debug, Authorization, session): the cache of pages (PageCache) and the validators apply their own.
     */
    public function fingerprint(PageTranslation $t, Request $request): ?string
    {
        if (null === $t->publishedAt) {
            return null;
        }
        $locale = $t->locale;
        $seo = '(SELECT CONCAT(COUNT(*), \'|\', COALESCE(MAX(updated_at)::text, \'\')) FROM itk_seo_rule)';
        $versions = '';
        $versionParams = [];
        $types = [];
        if ($this->locales?->isMultilingual()) {
            // A rule for another language can hide an alternate even when its stored timestamp has not changed.
            $seo = '(SELECT COALESCE(SUM(hashtext(to_jsonb(r)::text)), 0) FROM itk_seo_rule r)';
            $versions = ',
                (SELECT COALESCE(SUM(hashtext(CONCAT(locale, \'|\', COALESCE(published->>\'url\', \'\'), \'|\', COALESCE(published->\'seo\'->>\'robots\', \'\'), \'|\', published_at::text))), 0)
                    FROM itk_page_translation WHERE page_id = :pid AND status = :live4 AND published IS NOT NULL AND locale IN (:enabledLocales)) AS versions';
            $versionParams = ['pid' => $t->page->id, 'live4' => PageTranslation::PUBLISHED, 'enabledLocales' => $this->locales->codes()];
            $types = ['enabledLocales' => ArrayParameterType::STRING];
        }
        $row = $this->db->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM itk_page_translation WHERE locale = :locale AND status = :live) AS pages,
                (SELECT MAX(published_at) FROM itk_page_translation WHERE locale = :locale2 AND status = :live2) AS newest,
                (SELECT COALESCE(SUM(hashtext(CONCAT(setting_key, \'|\', locale, \'|\', COALESCE(setting_value::text, \'\')))), 0) FROM itk_setting) AS settings,
                (SELECT COALESCE(SUM(hashtext(CONCAT(p.id, \'|\', p.position, \'|\', COALESCE(p.parent_id, \'\')))), 0) FROM itk_page p INNER JOIN itk_page_translation pt ON pt.page_id = p.id AND pt.locale = :locale3 AND pt.status = :live3) AS tree,
                (SELECT COALESCE(SUM(hashtext(CONCAT(id, \'|\', crops::text))), 0) FROM itk_media_file WHERE crops IS NOT NULL) AS frames,
                (SELECT COALESCE(SUM(hashtext(CONCAT(area_key, \'|\', name, \'|\', depth, \'|\', items::text))), 0) FROM itk_menu_area) AS menus,
                (SELECT COALESCE(SUM(hashtext(CONCAT(id, \'|\', collection_key, \'|\', position, \'|\', COALESCE(parent_id, \'\'), \'|\', COALESCE(translations #>> CAST(:stamp AS text[]), translations #>> CAST(:updated AS text[]), \'\')))), 0) FROM itk_collection_item WHERE translations #>> CAST(:status AS text[]) = :itemLive) AS records,
                ' . $seo . ' AS seo,
                (SELECT CONCAT(basis, \'|\', status, \'|\', generated_at) FROM itk_critical_css WHERE translation_id = :tid) AS critical' . $versions,
            [
                'locale' => $locale, 'live' => PageTranslation::PUBLISHED, 'locale2' => $locale, 'live2' => PageTranslation::PUBLISHED, 'locale3' => $locale, 'live3' => PageTranslation::PUBLISHED,
                'stamp' => JsonPath::of($locale, 'publishedAt'), 'updated' => JsonPath::of($locale, 'updatedAt'), 'status' => JsonPath::of($locale, 'status'), 'itemLive' => 'published',
                'tid' => $t->id,
            ] + $versionParams,
            $types,
        );
        if (false === $row) {
            return null;
        }
        $published = $t->publishedAt;
        $newest = \is_string($row['newest']) ? new \DateTimeImmutable($row['newest']) : $published;

        $parts = [$this->build(), $t->page->id, $locale, $published->format('Y-m-d H:i:s'), \sha1(Json::encode(Json::canonical($t->published))), $request->getQueryString() ?? '', $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'), $this->stylesheets->stamp(), $this->analyticsStart, $this->analyticsAsset->version(), ...\array_values($row)];
        $fingerprint = \sha1(\implode("\x1f", $parts));
        $this->last = [$fingerprint, $newest > $published ? $newest : $published];

        return $fingerprint;
    }

    /**
     * @param string|null $fingerprint the result of fingerprint() for this page and request: the database is not asked again
     *
     * @return array{etag: string, lastModified: \DateTimeImmutable}|null null = no validators for this request
     */
    public function forPage(PageTranslation $t, Request $request, ?string $fingerprint = null): ?array
    {
        if (!$this->enabled || $this->debug || null === $t->publishedAt || $request->headers->has('Authorization') || $request->hasPreviousSession() || null !== CspNonce::fromRequest($request)) {
            return null;
        }
        if (null === $fingerprint || $fingerprint !== ($this->last[0] ?? null)) {
            $fingerprint = $this->fingerprint($t, $request);
        }
        if (null === $fingerprint || null === $this->last) {
            return null;
        }

        return ['etag' => '"' . \substr($fingerprint, 0, 24) . '"', 'lastModified' => $this->last[1]];
    }

    /** The build of the code and of the cache the site runs on: the time of the file of the compiled container. */
    public function build(): string
    {
        $file = $this->cacheDir . '/' . $this->containerClass . '.php';

        return $this->containerClass . ':' . (\is_file($file) ? (string) \filemtime($file) : '');
    }
}

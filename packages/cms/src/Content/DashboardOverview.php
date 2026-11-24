<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;

/** @internal Dashboard rows and language counts for the current editor. */
final class DashboardOverview
{
    private const RECENT = 8;

    public function __construct(
        private readonly Access $access,
        private readonly Schema $schema,
        private readonly PageTranslationRepository $pages,
        private readonly PageService $pageService,
        private readonly CollectionItemRepository $items,
        private readonly CollectionService $collections,
        private readonly RequestService $requests,
        private readonly ContentLocales $contentLocales,
        private readonly ChangeDetector $changes,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $unpublished = [];
        $awaiting = [];
        $recent = [];
        $locales = \array_fill_keys($this->contentLocales->codes(), ['pages' => ['count' => 0, 'unpublished' => 0], 'collections' => ['count' => 0, 'unpublished' => 0]]);
        $languageQuery = static fn (string $locale): string => \count($locales) > 1 ? '?lang=' . \rawurlencode($locale) : '';

        $enabled = $this->contentLocales->isConfigured() ? $this->contentLocales->codes() : [];
        $templates = $this->access->can('pages') ? ($this->access->me()['templates'] ?? null) : [];
        foreach ($this->pages->forOverview($enabled, $templates) as $t) {
            $template = $t['template'];
            $locale = $t['locale'];
            if (isset($locales[$locale])) {
                ++$locales[$locale]['pages']['count'];
            }
            $draft = PageTranslation::DRAFT === $t['status'];
            $live = PageTranslation::PUBLISHED === $t['status'] ? $t['published'] : null;
            $n = $t['draft'] === $live ? 0 : $this->changes->pageChangeCount($t['draft'], $template, $live);
            $kind = $this->schema->template($template)?->title() ?? $template;
            $title = (string) ($t['draft']['title'] ?? '');
            $to = '/pages/' . $t['pageId'] . $languageQuery($locale);
            if ($draft || $n > 0) {
                if (isset($locales[$locale])) {
                    ++$locales[$locale]['pages']['unpublished'];
                }
                $row = [
                    'kind' => 'page', 'id' => $t['pageId'], 'locale' => $locale, 'title' => $title, 'pageKind' => $kind, 'template' => $template,
                    'draft' => $draft, 'changes' => $n, 'updatedAt' => $this->pageService->iso($t['updatedAt']),
                    'migrated' => $t['migrated'], 'awaiting' => $t['awaiting'], 'to' => $to,
                ];
                $unpublished[] = $row;
                if (null !== $t['awaiting']) {
                    $awaiting[] = $row + ['by' => $t['awaiting']['by'] ?? '', 'at' => $t['awaiting']['at'] ?? null];
                }
            }
            if (null !== $live && null !== $t['publishedAt']) {
                $recent[] = ['label' => $kind . ' «' . $title . '»', 'locale' => $locale, 'to' => $to, 'at' => $this->pageService->iso($t['publishedAt'])];
            }
        }

        foreach ($this->schema->collections() as $c) {
            if (!$this->access->can('col:' . $c->key(), 'view') || !$c->isVisibleTo($this->access->viewer())) {
                continue;
            }
            foreach ($this->items->forOverview($c->key()) as $i) {
                foreach ($this->collections->summarizeTranslations($c, $i['translations']) as $locale => $s) {
                    if (null === $s || ($this->contentLocales->isConfigured() && !$this->contentLocales->has((string) $locale))) {
                        continue;
                    }
                    if (isset($locales[$locale])) {
                        ++$locales[$locale]['collections']['count'];
                    }
                    $title = '' !== (string) $s['title'] ? (string) $s['title'] : $c->title();
                    $to = '/collections/' . $c->key() . ('matrix' === $c->viewName() ? '' : '/' . $i['id']) . $languageQuery((string) $locale);
                    if ('draft' === $s['status'] || $s['changes'] > 0) {
                        if (isset($locales[$locale])) {
                            ++$locales[$locale]['collections']['unpublished'];
                        }
                        $row = [
                            'kind' => 'item', 'collection' => $c->key(), 'id' => $i['id'], 'locale' => (string) $locale, 'title' => $title, 'pageKind' => $c->title(),
                            'draft' => 'draft' === $s['status'], 'changes' => $s['changes'], 'updatedAt' => $s['updatedAt'], 'awaiting' => $s['awaiting'], 'to' => $to,
                        ];
                        $unpublished[] = $row;
                        if (null !== $s['awaiting']) {
                            $awaiting[] = $row + ['by' => $s['awaiting']['by'] ?? '', 'at' => $s['awaiting']['at'] ?? null];
                        }
                    } elseif (null !== $s['updatedAt']) {
                        $recent[] = ['label' => $c->title() . ' · ' . $title, 'locale' => (string) $locale, 'to' => $to, 'at' => $s['updatedAt']];
                    }
                }
            }
        }

        \usort($unpublished, static fn (array $a, array $b): int => \strcmp((string) $b['updatedAt'], (string) $a['updatedAt']));
        \usort($awaiting, static fn (array $a, array $b): int => \strcmp((string) $b['at'], (string) $a['at']));
        \usort($recent, static fn (array $a, array $b): int => \strcmp((string) $b['at'], (string) $a['at']));

        $canRequests = $this->access->can('requests', 'view');
        $requestCounts = $canRequests ? $this->requests->counts() : ['new' => 0, 'failed' => 0];

        return [
            'newRequests' => $canRequests ? \array_map($this->requests->present(...), $this->requests->search(['status' => 'new'], self::RECENT)) : [],
            'newRequestCount' => $requestCounts['new'],
            'failedCount' => $requestCounts['failed'],
            'unpublished' => $unpublished,
            'awaiting' => $awaiting,
            'proposals' => [],
            'recent' => \array_slice($recent, 0, self::RECENT),
            'languages' => $this->contentLocales->catalog(),
            'locales' => $locales,
        ];
    }
}

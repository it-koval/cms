<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\CollectionItemRepository;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Сегодня». GET dashboard →
 *   newRequests   requests with work status «new» (newest first, as in GET requests)       failedCount   requests not delivered to Telegram or MAX
 *   unpublished   [{ kind: 'page'|'item', id, locale, title, pageKind, template?|collection?, draft, changes, updatedAt, migrated?, awaiting, to }]  newest first
 *   awaiting      the same rows that wait for a publisher, + by, at                           proposals   [] (no AI in this iteration)
 *   recent        [{ label, to, at }] last published, newest first (8)                        languages   { main, list }
 * A person sees only what his rights show: requests, pages (by template) and collections.
 *
 * @internal
 */
#[AsController]
#[Route('/api/dashboard', name: 'itk_api_dashboard', methods: ['GET'])]
final class DashboardController extends AbstractApiController
{
    private const RECENT = 8;

    public function __construct(
        private readonly Access $access,
        private readonly Schema $schema,
        private readonly PageRepository $pages,
        private readonly PageService $pageService,
        private readonly CollectionItemRepository $items,
        private readonly CollectionService $collections,
        private readonly RequestService $requests,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (null === $this->access->user()) {
            throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        }
        $unpublished = [];
        $awaiting = [];
        $recent = [];

        foreach ($this->pages->findAllOrdered() as $p) {
            if (!$this->access->can('pages', 'view', $p->template)) {
                continue;
            }
            $kind = $this->schema->template($p->template)?->title() ?? $p->template;
            foreach ($p->translations as $locale => $t) {
                $title = (string) ($t->draft['title'] ?? '');
                $n = $this->pageService->changeCount($t);
                if (PageTranslation::DRAFT === $t->status || $n > 0) {
                    $row = [
                        'kind' => 'page', 'id' => $p->id, 'locale' => (string) $locale, 'title' => $title, 'pageKind' => $kind, 'template' => $p->template,
                        'draft' => PageTranslation::DRAFT === $t->status, 'changes' => $n, 'updatedAt' => $this->pageService->iso($t->updatedAt),
                        'migrated' => $t->migrated, 'awaiting' => $t->awaiting, 'to' => '/pages/' . $p->id,
                    ];
                    $unpublished[] = $row;
                    if (null !== $t->awaiting) {
                        $awaiting[] = $row + ['by' => $t->awaiting['by'] ?? '', 'at' => $t->awaiting['at'] ?? null];
                    }
                }
                if ($t->isLive() && null !== $t->publishedAt) {
                    $recent[] = ['label' => $kind . ' «' . $title . '»', 'to' => '/pages/' . $p->id, 'at' => $this->pageService->iso($t->publishedAt)];
                }
            }
        }

        foreach ($this->schema->collections() as $c) {
            if (!$this->access->can('col:' . $c->key(), 'view') || !$c->isVisibleTo($this->access->viewer())) {
                continue;
            }
            foreach ($this->items->findByCollection($c->key()) as $i) {
                foreach ($this->collections->summary($c, $i) as $locale => $s) {
                    if (null === $s) {
                        continue;
                    }
                    $title = '' !== (string) $s['title'] ? (string) $s['title'] : $c->title();
                    $to = '/collections/' . $c->key() . '/' . $i->id;
                    if ('draft' === $s['status'] || $s['changes'] > 0) {
                        $row = [
                            'kind' => 'item', 'collection' => $c->key(), 'id' => $i->id, 'locale' => (string) $locale, 'title' => $title, 'pageKind' => $c->title(),
                            'draft' => 'draft' === $s['status'], 'changes' => $s['changes'], 'updatedAt' => $s['updatedAt'], 'awaiting' => $s['awaiting'], 'to' => $to,
                        ];
                        $unpublished[] = $row;
                        if (null !== $s['awaiting']) {
                            $awaiting[] = $row + ['by' => $s['awaiting']['by'] ?? '', 'at' => $s['awaiting']['at'] ?? null];
                        }
                    } elseif (null !== $s['updatedAt']) {
                        $recent[] = ['label' => $c->title() . ' · ' . $title, 'to' => $to, 'at' => $s['updatedAt']];
                    }
                }
            }
        }

        \usort($unpublished, static fn (array $a, array $b): int => \strcmp((string) $b['updatedAt'], (string) $a['updatedAt']));
        \usort($awaiting, static fn (array $a, array $b): int => \strcmp((string) $b['at'], (string) $a['at']));
        \usort($recent, static fn (array $a, array $b): int => \strcmp((string) $b['at'], (string) $a['at']));

        $canRequests = $this->access->can('requests', 'view');

        return $this->json([
            'newRequests' => $canRequests ? \array_map($this->requests->present(...), $this->requests->search(['status' => 'new'])) : [],
            'failedCount' => $canRequests ? $this->requests->counts()['failed'] : 0,
            'unpublished' => $unpublished,
            'awaiting' => $awaiting,
            'proposals' => [],
            'recent' => \array_slice($recent, 0, self::RECENT),
            'languages' => MetaController::languagesOf($this->mainLocale),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\CriticalCss\Model\Basis;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\CriticalCss\Model\Freshness;
use Itk\Cms\CriticalCss\Model\GenerateCriticalCss;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Rendering\PageValidators;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Critical CSS of live pages, request side (the live render, the publish listener, the command): which basis a page wants, whether its row is good
 * for it, and asking the worker once. It reads itk_critical_css and never writes it; CriticalCssGenerator (worker side) is the one writer.
 *
 * @internal
 */
final class CriticalCssService
{
    /** How long "already asked for this basis" is remembered on the request side. Only a throttle: the handler re-checks everything. */
    private const ASKED_TTL = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Stylesheets $stylesheets,
        private readonly PageValidators $validators,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $asked,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * For PageRenderer::renderLive(): the styles to inline, or null (then the layout links the stylesheets the plain, blocking way).
     * When the row is Missing or Stale it also asks the worker once per basis, so a deploy that changes the CSS heals itself as pages are visited.
     * Never throws: a broken database or queue costs the visitor only the inlining.
     */
    public function forLiveRender(PageTranslation $t): ?CriticalStyles
    {
        if (!$this->stylesheets->enabled || !$t->isLive()) {
            return null;
        }
        try {
            $wanted = $this->wanted($t);
            $row = $this->em->find(CriticalCss::class, $t->id);
            $state = Freshness::of($row, $wanted);
            if ($state->wantsRun()) {
                $this->ask($t, $wanted, When::Outdated);
            }
        } catch (\Throwable) {
            return null;
        }

        return Freshness::Ready === $state && null !== $row && null !== $row->styles && [] !== $row->styles ? CriticalStyles::fromStored($row->styles) : null;
    }

    /**
     * Asks the worker to bring one page up to date when $when admits its Freshness. Returns whether a message was sent.
     * The publish listener uses Outdated, the command NotReady and Always; safe to call any number of times.
     */
    public function request(PageTranslation $t, When $when = When::Outdated): bool
    {
        if (!$this->stylesheets->enabled || !$t->isLive()) {
            return false;
        }
        $wanted = $this->wanted($t);
        if (!$when->admits(Freshness::of($this->em->find(CriticalCss::class, $t->id), $wanted))) {
            return false;
        }

        return $this->ask($t, $wanted, $when);
    }

    /** The basis the page wants right now (the snapshot, the build and the stylesheet stamp). The generator stamps rows with it. */
    public function wanted(PageTranslation $t): Basis
    {
        return Basis::of($t->published ?? [], $this->validators->build(), $this->stylesheets->stamp());
    }

    /**
     * Sends GenerateCriticalCss once per (translation, basis) per ASKED_TTL: a stale page rendered a thousand times while the worker is busy sends one message.
     * The marker is a cache key, not a column: the request side never writes itk_critical_css, and losing the marker only costs a duplicate message
     * that the handler drops after one SELECT. An ask wider than Outdated (the command) always sends.
     */
    private function ask(PageTranslation $t, Basis $wanted, When $when): bool
    {
        $key = 'itk_critical_css_asked_' . \sha1($t->id . '|' . $wanted->value);
        try {
            $marker = $this->asked->getItem($key);
            if (When::Outdated === $when && $marker->isHit()) {
                return false;
            }
            $this->bus->dispatch(new GenerateCriticalCss($t->id, $when));
            $this->asked->save($marker->set(true)->expiresAfter(self::ASKED_TTL));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Critical CSS of page version {id} could not be queued: {error}', ['id' => $t->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}

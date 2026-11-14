<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\CriticalCss\CriticalCssGenerator;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * itk:critical-css:generate: the site-wide path, for what fires no event (a backfill after turning the feature on, a retry of failed pages,
 * a crittr upgrade) and for checking the Node installation by hand.
 *
 *   itk:critical-css:generate               queue every live page version that is Missing, Stale or Failed (this is how failed pages are retried)
 *   --all                                   every live page version, Ready ones too
 *   --page=<id> --locale=<xx>               only that page, only that language
 *   --now                                   do the work in this process instead of queueing, PAGES_PER_RUN pages per Node process, each failure printed;
 *                                           exit code 1 when any page failed, so a deploy script notices a broken Node install
 *   --dry-run                               list what would be queued, send nothing
 *
 * Safe to run any number of times.
 * Not needed after a CSS deploy: the first render of each page asks for it (CriticalCssService::forLiveRender); running it in the deploy script only does it sooner.
 */
#[AsCommand(name: 'itk:critical-css:generate', description: 'ops.cmd_critical_css_description')]
final class GenerateCriticalCssCommand extends Command
{
    /** Pages per Node process with --now: short enough to see progress, long enough to pay for starting Chrome once. */
    private const PAGES_PER_RUN = 5;

    public function __construct(
        private readonly Stylesheets $stylesheets,
        private readonly CriticalCssService $critical,
        private readonly CriticalCssGenerator $generator,
        private readonly PageRepository $pages,
        private readonly PageTranslationRepository $translations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, I18n::t('ops.critical_css_all_option'));
        $this->addOption('page', null, InputOption::VALUE_REQUIRED, I18n::t('ops.critical_css_page_option'));
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, I18n::t('ops.critical_css_locale_option'));
        $this->addOption('now', null, InputOption::VALUE_NONE, I18n::t('ops.critical_css_now_option'));
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, I18n::t('ops.critical_css_dry_run_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->stylesheets->enabled) {
            $io->error(I18n::t('ops.critical_css_off'));

            return Command::INVALID;
        }
        $when = $input->getOption('all') ? When::Always : When::NotReady;
        $live = $this->live(\is_string($input->getOption('page')) ? $input->getOption('page') : null, \is_string($input->getOption('locale')) ? $input->getOption('locale') : null);
        if ([] === $live) {
            $io->warning(I18n::t('ops.critical_css_nothing'));

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $due = \array_values(\array_filter($live, fn (PageTranslation $t): bool => $when->admits($this->critical->state($t))));
            $io->listing(\array_map(fn (PageTranslation $t): string => $this->label($t) . ': ' . $this->critical->state($t)->name, $due));
            $io->note(I18n::t('ops.critical_css_dry_run', ['count' => \count($due), 'total' => \count($live)]));

            return Command::SUCCESS;
        }

        if (!$input->getOption('now')) {
            $queued = 0;
            foreach ($live as $t) {
                $queued += (int) $this->critical->request($t, $when);
            }
            $io->success(I18n::t('ops.critical_css_queued', ['queued' => $queued, 'fresh' => \count($live) - $queued]));

            return Command::SUCCESS;
        }

        $generated = $reused = $skipped = 0;
        $failed = [];
        $bar = $io->createProgressBar(\count($live));
        $bar->start();
        foreach (\array_chunk($live, self::PAGES_PER_RUN) as $chunk) {
            $ids = [];
            foreach ($chunk as $t) {
                $ids[$t->id] = $when;
            }
            $report = $this->generator->generate($ids);
            $generated += $report->generated;
            $reused += $report->reused;
            $skipped += $report->skipped;
            $failed += $report->failed;
            $bar->advance(\count($chunk));
        }
        $bar->finish();
        $io->newLine(2);
        $byId = [];
        foreach ($live as $t) {
            $byId[$t->id] = $t;
        }
        foreach ($failed as $id => $reason) {
            $io->text(I18n::t('ops.critical_css_failed_line', ['page' => isset($byId[$id]) ? $this->label($byId[$id]) : $id, 'reason' => $reason]));
        }
        $summary = I18n::t('ops.critical_css_done', ['generated' => $generated, 'reused' => $reused, 'skipped' => $skipped, 'failed' => \count($failed)]);
        if ([] !== $failed) {
            $io->error($summary);

            return Command::FAILURE;
        }
        $io->success($summary);

        return Command::SUCCESS;
    }

    /** @return list<PageTranslation> */
    private function live(?string $pageId, ?string $locale): array
    {
        if (null !== $pageId) {
            $page = $this->pages->find($pageId);
            $all = null === $page ? [] : \array_values(\array_filter($page->translations->toArray(), static fn (PageTranslation $t): bool => $t->isLive()));
        } else {
            $all = $this->translations->findAllLive();
        }

        return \array_values(\array_filter($all, static fn (PageTranslation $t): bool => null === $locale || $t->locale === $locale));
    }

    private function label(PageTranslation $t): string
    {
        return (string) ($t->published['url'] ?? $t->publishedUrl ?? $t->id) . ' (' . $t->locale . ')';
    }
}

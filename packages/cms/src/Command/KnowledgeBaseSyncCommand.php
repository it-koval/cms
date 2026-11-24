<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\I18n\I18n;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Indexer;
use Itk\Cms\KnowledgeBase\KnowledgeBaseOptions;
use Itk\Cms\KnowledgeBase\Model\Freshness;
use Itk\Cms\KnowledgeBase\Model\Outcome;
use Itk\Cms\KnowledgeBase\Model\Planned;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Itk\Cms\KnowledgeBase\Model\SyncReport;
use Itk\Cms\KnowledgeBase\SourceReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * itk:knowledge-base:sync: the site-wide path, for what fires no event: turning the feature on (backfill), a deploy that changed the schema,
 * the extraction rules or the config (excluded templates), a change of model. The one way to reindex.
 *
 *   itk:knowledge-base:sync                  plan every source (live ones and stored documents), queue those Missing, Stale or Orphan
 *   --now                                    Indexer::sync() in this process, SYNC_PER_RUN sources at a time (the EntityManager is cleared after each), each failure printed;
 *                                            exit 1 when any source failed or the embedder refused (a deploy script notices a bad key)
 *   --dry-run                                counts per Freshness, inputs to embed, estimated tokens; sends nothing, calls no API
 *   --source=page:<id>[:<locale>]            only that source (every language without a locale); also item:<id>[:<locale>]
 *
 * Safe to run any number of times and on every deploy: a Current document costs one extraction, no API call.
 * There is no --all: a rewrite of a Current document would reuse the same vectors by hash and change nothing.
 */
#[AsCommand(name: 'itk:knowledge-base:sync', description: 'ops.cmd_knowledge_base_sync_description')]
final class KnowledgeBaseSyncCommand extends Command
{
    private const SYNC_PER_RUN = 32;

    public function __construct(
        private readonly Indexer $indexer,
        private readonly SourceReader $reader,
        private readonly MessageBusInterface $bus,
        private readonly KnowledgeBaseOptions $options,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('now', null, InputOption::VALUE_NONE, I18n::t('ops.knowledge_base_now_option'));
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, I18n::t('ops.knowledge_base_dry_run_option'));
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, I18n::t('ops.knowledge_base_source_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->options->enabled) {
            $io->error(I18n::t('ops.knowledge_base_off'));

            return Command::INVALID;
        }
        $source = $input->getOption('source');
        if (\is_string($source)) {
            try {
                [$kind, $id, $locale] = SourceRef::parseOption($source);
            } catch (\InvalidArgumentException $e) {
                $io->error(I18n::t('ops.knowledge_base_bad_source', ['reason' => $e->getMessage()]));

                return Command::INVALID;
            }
            $refs = null === $locale ? $this->reader->versionsOf(new SourceRef($kind, $id, '')) : [new SourceRef($kind, $id, $locale)];
        } else {
            $refs = $this->indexer->everything();
            $this->em->clear();
        }
        if ([] === $refs) {
            $io->warning(I18n::t('ops.knowledge_base_nothing'));

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $planned = $this->indexer->plan($refs);
            $counts = [];
            foreach (Freshness::cases() as $state) {
                $counts[$state->name] = \count(\array_filter($planned, static fn (Planned $p): bool => $p->state === $state));
            }
            $io->note(I18n::t('ops.knowledge_base_dry_run', [
                'missing' => $counts['Missing'],
                'stale' => $counts['Stale'],
                'current' => $counts['Current'],
                'orphan' => $counts['Orphan'],
                'inputs' => \array_sum(\array_map(static fn (Planned $p): int => $p->newInputs, $planned)),
                'tokens' => \array_sum(\array_map(static fn (Planned $p): int => $p->estimatedTokens, $planned)),
            ]));

            return Command::SUCCESS;
        }

        if (!$input->getOption('now')) {
            $queued = 0;
            foreach ($this->indexer->plan($refs) as $planned) {
                if ($planned->state->needsWork()) {
                    $this->bus->dispatch(new SyncKnowledge($planned->ref->kind->value, $planned->ref->id, $planned->ref->locale));
                    ++$queued;
                }
            }
            $io->success(I18n::t('ops.knowledge_base_queued', ['queued' => $queued, 'fresh' => \count($refs) - $queued]));

            return Command::SUCCESS;
        }

        $totals = ['Written' => 0, 'Unchanged' => 0, 'Removed' => 0, 'Superseded' => 0, 'Failed' => 0];
        $embedded = $reused = $tokens = 0;
        $failures = [];
        $bar = $io->createProgressBar(\count($refs));
        $bar->start();
        foreach (\array_chunk($refs, self::SYNC_PER_RUN) as $chunk) {
            try {
                $report = $this->indexer->sync($chunk, retryTransient: false);
            } catch (ConnectionException|RetryableException $e) {
                // Shared batch reads can fail before a per-source outcome exists. Report the affected sources and keep the run's summary.
                $this->em->getConnection()->close();
                $outcomes = $errors = [];
                foreach ($chunk as $ref) {
                    $outcomes[$ref->key()] = Outcome::Failed;
                    $errors[$ref->key()] = $e->getMessage();
                }
                $report = new SyncReport($outcomes, $errors, 0, 0, null);
            } catch (EmbeddingFailed $e) {
                $bar->clear();
                $io->error(I18n::t('ops.knowledge_base_embedder_failed', ['reason' => $e->getMessage()]));

                return Command::FAILURE;
            }
            foreach ($report->counts() as $name => $n) {
                $totals[$name] += $n;
            }
            $embedded += $report->embedded;
            $reused += $report->reused;
            $tokens += $report->tokens ?? 0;
            $failures += $report->failures;
            $this->em->clear();
            $bar->advance(\count($chunk));
        }
        $bar->finish();
        $io->newLine(2);
        foreach ($failures as $key => $reason) {
            $io->text(I18n::t('ops.knowledge_base_failed_line', ['source' => $key, 'reason' => $reason]));
        }
        $summary = I18n::t('ops.knowledge_base_done', $totals + ['embedded' => $embedded, 'reused' => $reused, 'tokens' => $tokens]);
        if ([] !== $failures) {
            $io->error($summary);

            return Command::FAILURE;
        }
        $io->success($summary);

        return Command::SUCCESS;
    }
}

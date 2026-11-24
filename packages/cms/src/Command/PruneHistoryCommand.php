<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops the history beyond its depth (`itk_cms.history.keep` entries of an object, `itk_cms.history.days` days). Meant for the schedule: once a day.
 *
 * @internal
 */
#[AsCommand(name: 'itk:history:prune', description: 'ops.cmd_prune_history_description')]
final class PruneHistoryCommand extends Command
{
    public function __construct(private readonly HistoryService $history)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, I18n::t('ops.prune_history_keep_option'))
            ->addOption('days', null, InputOption::VALUE_REQUIRED, I18n::t('ops.prune_history_days_option'))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, I18n::t('ops.prune_dry_run_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keep = $input->getOption('keep');
        $days = $input->getOption('days');
        foreach (['keep' => $keep, 'days' => $days] as $name => $v) {
            if (null !== $v && (!\ctype_digit((string) $v) || (int) $v < 1)) {
                $io->error(I18n::t('ops.prune_option_positive', ['name' => $name]));

                return Command::INVALID;
            }
        }
        $dry = (bool) $input->getOption('dry-run');
        $done = $this->history->prune(null !== $keep ? (int) $keep : null, null !== $days ? (int) $days : null, $dry);
        $io->success(I18n::t($dry ? 'ops.prune_history_would' : 'ops.prune_history_done', ['old' => $done['old'], 'extra' => $done['extra']]));

        return Command::SUCCESS;
    }
}

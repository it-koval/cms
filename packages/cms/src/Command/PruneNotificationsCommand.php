<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Notification\NotificationInbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Drops old notifications of the bell together with their «прочитано» marks. Meant for the schedule: once a day. */
#[AsCommand(name: 'itk:notifications:prune', description: 'ops.cmd_prune_notifications_description')]
final class PruneNotificationsCommand extends Command
{
    public function __construct(private readonly NotificationInbox $inbox)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, I18n::t('ops.prune_notifications_days_option'), '90')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, I18n::t('ops.prune_dry_run_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (string) $input->getOption('days');
        if (!\ctype_digit($days) || (int) $days < 1) {
            $io->error(I18n::t('ops.prune_option_positive', ['name' => 'days']));

            return Command::INVALID;
        }
        $dry = (bool) $input->getOption('dry-run');
        $io->success(I18n::t($dry ? 'ops.prune_notifications_would' : 'ops.prune_notifications_done', ['count' => $this->inbox->prune((int) $days, $dry)]));

        return Command::SUCCESS;
    }
}

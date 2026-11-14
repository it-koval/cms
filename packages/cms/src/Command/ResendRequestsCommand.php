<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\RequestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'itk:requests:resend', description: 'ops.cmd_resend_description')]
final class ResendRequestsCommand extends Command
{
    public function __construct(private readonly RequestService $requests)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(I18n::t('ops.resend_queued', ['count' => $this->requests->requeueFailed()]));

        return Command::SUCCESS;
    }
}

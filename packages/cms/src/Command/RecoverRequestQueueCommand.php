<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Request\RequestDeliverer;
use Itk\Cms\Request\RequestQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'itk:requests:recover', description: 'Publish committed request notification intents that have not reached Messenger.')]
final class RecoverRequestQueueCommand extends Command
{
    public function __construct(private readonly RequestQueue $queue, private readonly RequestDeliverer $deliverer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->deliverer->recoverAnnouncements(500);
        $output->writeln((string) $this->queue->recover(500));

        return Command::SUCCESS;
    }
}

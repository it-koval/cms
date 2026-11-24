<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Mail\RequestMailCheckpoint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'itk:requests:mail:resolve', description: 'Record the verified SMTP outcome of an interrupted request email')]
final class ResolveRequestMailCommand extends Command
{
    public function __construct(private readonly RequestMailCheckpoint $checkpoint)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('request', InputArgument::REQUIRED, 'Request number')
            ->addArgument('recipient', InputArgument::REQUIRED, 'Email address')
            ->addArgument('outcome', InputArgument::REQUIRED, 'sent or rejected, verified against the SMTP server')
            ->setHelp('Check the SMTP server first. "sent" prevents resending; "rejected" permits a subsequent messenger:failed:retry.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = (string) $input->getArgument('request');
        $recipient = \trim((string) $input->getArgument('recipient'));
        $outcome = (string) $input->getArgument('outcome');
        if (!\ctype_digit($request) || (int) $request < 1 || false === \filter_var($recipient, \FILTER_VALIDATE_EMAIL) || !\in_array($outcome, ['sent', 'rejected'], true)) {
            $output->writeln('<error>Provide a positive request number, valid recipient and verified outcome: sent or rejected.</error>');

            return Command::INVALID;
        }
        if (!$this->checkpoint->resolve((string) (int) $request, $recipient, 'sent' === $outcome)) {
            $output->writeln('<error>No interrupted delivery matches this request and recipient.</error>');

            return Command::FAILURE;
        }
        $output->writeln('sent' === $outcome ? 'Accepted email recorded. Duplicate envelopes will not send it again.' : 'Rejection recorded. Retry the envelope with messenger:failed:retry.');

        return Command::SUCCESS;
    }
}

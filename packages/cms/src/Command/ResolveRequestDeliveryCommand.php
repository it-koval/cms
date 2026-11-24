<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\RequestDeliverer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'itk:requests:resolve', description: 'ops.cmd_requests_resolve_description')]
final class ResolveRequestDeliveryCommand extends Command
{
    public function __construct(private readonly RequestDeliverer $deliverer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('request', InputArgument::REQUIRED, I18n::t('ops.requests_resolve_request'))
            ->addArgument('channel', InputArgument::REQUIRED, I18n::t('ops.requests_resolve_channel'))
            ->addArgument('recipient', InputArgument::REQUIRED, I18n::t('ops.requests_resolve_recipient'))
            ->addArgument('outcome', InputArgument::REQUIRED, I18n::t('ops.requests_resolve_outcome'))
            ->setHelp(I18n::t('ops.requests_resolve_help'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = (string) $input->getArgument('request');
        $channel = (string) $input->getArgument('channel');
        $recipient = \trim((string) $input->getArgument('recipient'));
        $outcome = (string) $input->getArgument('outcome');
        if (!\ctype_digit($request) || (int) $request < 1 || !isset(Request::CHANNELS[$channel]) || '' === $recipient || \preg_match('/[\x00-\x20]/', $recipient) || !\in_array($outcome, ['sent', 'rejected'], true)) {
            $output->writeln('<error>' . I18n::t('ops.requests_resolve_invalid') . '</error>');

            return Command::INVALID;
        }
        if (!$this->deliverer->resolve((int) $request, $channel, $recipient, 'sent' === $outcome)) {
            $output->writeln('<error>' . I18n::t('ops.requests_resolve_missing') . '</error>');

            return Command::FAILURE;
        }
        $command = \sprintf('itk:requests:resend %d --channel=%s', (int) $request, $channel);
        $key = '*' === $recipient
            ? ('sent' === $outcome ? 'ops.requests_resolve_legacy_sent' : 'ops.requests_resolve_legacy_rejected')
            : ('sent' === $outcome ? 'ops.requests_resolve_sent' : 'ops.requests_resolve_rejected');
        $output->writeln(I18n::t($key, ['command' => $command]));

        return Command::SUCCESS;
    }
}

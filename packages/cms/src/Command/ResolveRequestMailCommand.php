<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\RequestMailCheckpoint;
use Itk\Cms\Mail\RequestMailWarnings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'itk:requests:mail:resolve', description: 'ops.cmd_requests_mail_resolve_description')]
final class ResolveRequestMailCommand extends Command
{
    public function __construct(
        private readonly RequestMailCheckpoint $checkpoint,
        private readonly RequestMailWarnings $warnings,
        private readonly MessengerMonitor $messenger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('request', InputArgument::REQUIRED, I18n::t('ops.requests_mail_resolve_request'))
            ->addArgument('recipient', InputArgument::OPTIONAL, I18n::t('ops.requests_mail_resolve_recipient'))
            ->addArgument('outcome', InputArgument::OPTIONAL, I18n::t('ops.requests_mail_resolve_outcome'))
            ->addOption('legacy-checked', null, InputOption::VALUE_NONE, I18n::t('ops.requests_mail_resolve_legacy_checked'))
            ->setHelp(I18n::t('ops.requests_mail_resolve_help'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = (string) $input->getArgument('request');
        if ($input->getOption('legacy-checked')) {
            if (!\ctype_digit($request) || (int) $request < 1 || null !== $input->getArgument('recipient') || null !== $input->getArgument('outcome')) {
                $output->writeln('<error>' . I18n::t('ops.requests_mail_resolve_legacy_invalid') . '</error>');

                return Command::INVALID;
            }
            if (!$this->warnings->resolveLegacy((string) (int) $request)) {
                $output->writeln('<error>' . I18n::t('ops.requests_mail_resolve_legacy_missing') . '</error>');

                return Command::FAILURE;
            }
            $output->writeln(I18n::t('ops.requests_mail_resolve_legacy_done'));

            return Command::SUCCESS;
        }
        $recipient = \trim((string) $input->getArgument('recipient'));
        $outcome = (string) $input->getArgument('outcome');
        if (!\ctype_digit($request) || (int) $request < 1 || false === \filter_var($recipient, \FILTER_VALIDATE_EMAIL) || !\in_array($outcome, ['sent', 'rejected'], true)) {
            $output->writeln('<error>' . I18n::t('ops.requests_mail_resolve_invalid') . '</error>');

            return Command::INVALID;
        }
        $requestId = (string) (int) $request;
        $accepted = 'sent' === $outcome;
        if (!$this->checkpoint->resolve($requestId, $recipient, $accepted, $accepted && $this->warnings->hasLegacyWarning($requestId))) {
            $output->writeln('<error>' . I18n::t('ops.requests_mail_resolve_missing') . '</error>');

            return Command::FAILURE;
        }
        if ($accepted) {
            $this->warnings->accepted($requestId, $recipient);
            $output->writeln(I18n::t('ops.requests_mail_resolve_sent'));
        } else {
            $command = \sprintf('itk:requests:resend %s --channel=mail --recipient=%s', $requestId, \escapeshellarg($recipient));
            $output->writeln(I18n::t('ops.requests_mail_resolve_rejected', ['command' => $command]));
            $retry = $this->messenger->failedMailRetry($requestId, $recipient);
            if (null !== $retry) {
                $output->writeln(I18n::t('ops.requests_mail_resolve_failed_envelope', ['command' => $retry]));
            }
        }

        return Command::SUCCESS;
    }
}

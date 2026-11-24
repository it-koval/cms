<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Mail\RequestMailCheckpoint;
use Itk\Cms\Repository\RequestRepository;
use Itk\Cms\Request\RequestQueue;
use Itk\Cms\Request\RequestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'itk:requests:resend', description: 'ops.cmd_resend_description')]
final class ResendRequestsCommand extends Command
{
    public function __construct(
        private readonly RequestService $requests,
        private readonly RequestRepository $repository,
        private readonly RequestMailCheckpoint $checkpoint,
        private readonly MailComposer $mail,
        private readonly RequestQueue $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('request', InputArgument::OPTIONAL, I18n::t('ops.requests_resolve_request'))
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, I18n::t('ops.resend_channel'))
            ->addOption('recipient', null, InputOption::VALUE_REQUIRED, I18n::t('ops.requests_mail_resolve_recipient'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null !== $input->getArgument('request') || null !== $input->getOption('channel') || null !== $input->getOption('recipient')) {
            return isset(Request::CHANNELS[(string) $input->getOption('channel')])
                ? $this->retryMessenger($input, $output)
                : $this->retryMail($input, $output);
        }
        $result = $this->requests->requeueFailedResult();
        $output->writeln(I18n::t('ops.resend_queued', ['count' => $result['queued']]));
        if ($result['queued'] > 0) {
            $output->writeln(I18n::t('ops.resend_worker'));
        }
        $this->writeOutcomes($output, $result);

        return Command::SUCCESS;
    }

    private function retryMessenger(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('request');
        $channel = (string) $input->getOption('channel');
        if (!\ctype_digit($id) || (int) $id < 1 || null !== $input->getOption('recipient')) {
            $output->writeln('<error>' . I18n::t('ops.resend_targeted_invalid') . '</error>');

            return Command::INVALID;
        }
        $request = $this->repository->find((int) $id);
        if (null === $request || Request::DELIVERY_FAILED !== $request->channelState($channel)) {
            $output->writeln('<error>' . I18n::t('ops.resend_targeted_missing') . '</error>');

            return Command::FAILURE;
        }
        $result = $this->requests->resendResult((int) $id, $channel);
        if (\in_array($channel, $result['queuedChannels'], true)) {
            $output->writeln(I18n::t('ops.resend_queued', ['count' => 1]));
            $output->writeln(I18n::t('ops.resend_worker'));

            return Command::SUCCESS;
        }
        $state = 'uncertain' === $result['request']->channelJobPhase($channel) ? 'uncertain' : $result['outcomes'][$channel]['state'];
        $counts = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'uncertain' => 0];
        $counts[$state] = 1;
        $this->writeOutcomes($output, $counts);

        return \in_array($state, ['failed', 'uncertain'], true) ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param array{sent: int, failed: int, pending: int, uncertain: int} $counts */
    private function writeOutcomes(OutputInterface $output, array $counts): void
    {
        if ($counts['sent'] + $counts['failed'] + $counts['pending'] > 0) {
            $output->writeln(I18n::t('ops.resend_outcomes', $counts));
        }
        if ($counts['uncertain'] > 0) {
            $output->writeln(I18n::t('ops.resend_uncertain', ['count' => $counts['uncertain']]));
        }
    }

    private function retryMail(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('request');
        $recipient = \trim((string) $input->getOption('recipient'));
        if ('mail' !== $input->getOption('channel') || !\ctype_digit($id) || (int) $id < 1 || false === \filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>' . I18n::t('ops.resend_mail_invalid') . '</error>');

            return Command::INVALID;
        }
        $request = $this->repository->find((int) $id);
        if (null === $request || !$this->checkpoint->isRejected((string) $request->id, $recipient)) {
            $output->writeln('<error>' . I18n::t('ops.resend_mail_missing') . '</error>');

            return Command::FAILURE;
        }
        $this->queue->enqueueMail($this->mail->newRequest($request, $recipient));
        $output->writeln(I18n::t('ops.resend_mail_requested'));

        return Command::SUCCESS;
    }
}

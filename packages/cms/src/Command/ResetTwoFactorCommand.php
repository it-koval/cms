<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * For the case nobody can sign in: the only administrator lost the phone and the recovery codes. He sets the second factor up again at the next sign-in.
 *
 * @internal
 */
#[AsCommand(name: 'itk:user:reset-2fa', description: 'ops.cmd_reset_2fa_description')]
final class ResetTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorService $factor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, I18n::t('ops.user_email_argument'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findOneByEmail((string) $input->getArgument('email'));
        if (null === $user) {
            $output->writeln('<error>' . I18n::t('ops.user_not_found') . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(I18n::t($this->factor->reset($user) ? 'ops.reset_2fa_done' : 'ops.reset_2fa_none'));

        return Command::SUCCESS;
    }
}

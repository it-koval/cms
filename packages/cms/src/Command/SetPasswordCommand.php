<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** For the case nobody can sign in (the only administrator forgot the password): the server owner sets one from the console. */
#[AsCommand(name: 'itk:user:password', description: 'ops.cmd_set_password_description')]
final class SetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, I18n::t('ops.user_email_argument'))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, I18n::t('ops.set_password_option', ['min' => UserManager::MIN_PASSWORD_LENGTH]))
            ->setHelp(I18n::t('ops.set_password_help'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $this->users->findOneByEmail(\mb_strtolower(\trim((string) $input->getArgument('email'))));
        if (null === $user) {
            $io->error(I18n::t('ops.user_not_found'));

            return Command::FAILURE;
        }
        $password = $input->getOption('password');
        if (!\is_string($password) || '' === $password) {
            if (!$input->isInteractive()) {
                $io->error(I18n::t('ops.set_password_required'));

                return Command::FAILURE;
            }
            $password = (string) $io->askHidden(I18n::t('ops.set_password_question', ['min' => UserManager::MIN_PASSWORD_LENGTH]));
            if ($password !== (string) $io->askHidden(I18n::t('ops.set_password_repeat'))) {
                $io->error(I18n::t('ops.set_password_mismatch'));

                return Command::FAILURE;
            }
        }
        try {
            UserManager::checkPasswordPolicy($password);
            $this->manager->setPassword($user, $password);
        } catch (ContentException $e) {
            $io->error(I18n::t('ops.set_password_error', ['error' => $e->getMessage()]));

            return Command::FAILURE;
        }
        $io->success(I18n::t('ops.set_password_done', ['email' => $user->email]));

        return Command::SUCCESS;
    }
}

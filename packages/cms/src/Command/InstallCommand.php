<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Health\AppSecretHealthCheck;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Security\RoleSeeder;
use Itk\Cms\Security\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * First-time setup (safe to run again): built-in roles, menu areas from the schema, default settings, the home page, the first administrator.
 * Run after doctrine:migrations:migrate.
 */
#[AsCommand(name: 'itk:install', description: 'ops.cmd_install_description')]
final class InstallCommand extends Command
{
    private const MAX_PASSWORD_FILE_BYTES = 4096;

    public function __construct(
        private readonly RoleSeeder $roles,
        private readonly MenuService $menus,
        private readonly SettingsService $settings,
        private readonly PageService $pages,
        private readonly PageRepository $pageRepo,
        private readonly UserRepository $users,
        private readonly UserManager $userManager,
        private readonly AdminLocale $locale,
        /** @var ServiceProviderInterface<object> every messenger transport of the site by its name (and by service id) */
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceProviderInterface $transports,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, I18n::t('ops.install_email_option'))
            ->addOption('name', null, InputOption::VALUE_REQUIRED, I18n::t('ops.install_name_option'))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, I18n::t('ops.install_password_option', ['min' => UserManager::MIN_PASSWORD_LENGTH]))
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, I18n::t('ops.install_password_file_option', ['min' => UserManager::MIN_PASSWORD_LENGTH]))
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, I18n::t('ops.install_site_name_option'))
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, I18n::t('ops.install_domain_option'))
            ->addOption('no-home', null, InputOption::VALUE_NONE, I18n::t('ops.install_no_home_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === \trim($this->secret)) {
            $io->warning(AppSecretHealthCheck::emptyMessage());
        }
        $io->writeln(I18n::t('ops.install_roles', ['count' => $this->roles->seed()]));
        $io->writeln(I18n::t('ops.install_menus', ['count' => $this->menus->syncSchemaAreas()]));
        $this->setupTransports($io);
        $this->defaultSettings($input, $io);

        if (!$input->getOption('no-home') && [] === $this->pageRepo->findAll()) {
            try {
                // the title is saved with the site: in the language of its content, whatever language the console speaks
                $title = $this->locale->runContent(static fn (): string => I18n::t('model.core_home_name'));
                $this->pages->create(['title' => $title, 'url' => '/', 'template' => 'home']);
                $io->writeln(I18n::t('ops.install_home_created'));
            } catch (ContentException $e) {
                $io->warning(I18n::t('ops.install_home_failed', ['error' => $e->getMessage()]));
            }
        }

        if ($this->users->countActiveWithRole(Role::ADMIN) > 0 && !$input->getOption('email')) {
            $io->writeln(I18n::t('ops.install_admin_exists'));
            $io->success(I18n::t('ops.done'));

            return Command::SUCCESS;
        }

        $email = $input->getOption('email');
        if (!$email && $input->isInteractive()) {
            $email = $io->ask(I18n::t('ops.install_email_option'));
        }
        if (!$email) {
            $io->warning(I18n::t('ops.install_admin_not_created_email'));

            return Command::SUCCESS;
        }
        if (null !== $this->users->findOneByEmail((string) $email)) {
            $io->writeln(I18n::t('ops.install_user_exists'));
            $io->success(I18n::t('ops.done'));

            return Command::SUCCESS;
        }
        $passwordFile = $input->getOption('password-file');
        if (\is_string($passwordFile)) {
            $raw = \is_file($passwordFile) ? @\file_get_contents($passwordFile, false, null, 0, self::MAX_PASSWORD_FILE_BYTES + 1) : false;
            $password = false === $raw ? '' : \rtrim($raw, "\r\n");
            if (false === $raw || \strlen($raw) > self::MAX_PASSWORD_FILE_BYTES || '' === $password) {
                $io->error(I18n::t('ops.install_password_file_invalid'));

                return Command::FAILURE;
            }
        } else {
            $password = $input->getOption('password') ?: (\getenv('ITK_ADMIN_PASSWORD') ?: null);
        }
        if (!$password && $input->isInteractive()) {
            $q = (new Question(I18n::t('ops.install_password_question', ['min' => UserManager::MIN_PASSWORD_LENGTH])))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($q);
        }
        if (!$password) {
            $io->warning(I18n::t('ops.install_admin_not_created_password'));

            return Command::SUCCESS;
        }
        try {
            $name = $input->getOption('name') ?: $this->locale->run($this->locale->default(), static fn (): string => I18n::t('i18n.role_admin_name')); // the default name is saved with the user
            $this->userManager->create((string) $email, (string) $name, Role::ADMIN, (string) $password, User::ACTIVE);
        } catch (ContentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(I18n::t('ops.install_admin_created', ['email' => $email]));

        return Command::SUCCESS;
    }

    /** Tables of the queue (doctrine://) are made now, not by the first message: the worker and the form of the site find them ready. Transports that need no setup (sync, in-memory) are skipped. */
    private function setupTransports(SymfonyStyle $io): void
    {
        $done = 0;
        foreach (\array_keys($this->transports->getProvidedServices()) as $name) {
            if (\str_contains((string) $name, '.')) { // the locator knows each transport twice: by its name and by its service id
                continue;
            }
            try {
                $transport = $this->transports->get((string) $name);
                if ($transport instanceof SetupableTransportInterface) {
                    $transport->setup();
                    ++$done;
                }
            } catch (\Throwable $e) {
                $io->warning(I18n::t('ops.install_queue_failed', ['name' => $name, 'error' => MessengerMonitor::scrub($e->getMessage())]));
            }
        }
        $io->writeln(I18n::t('ops.install_queue_done', ['count' => $done]));
    }

    private function defaultSettings(InputInterface $input, SymfonyStyle $io): void
    {
        $current = $this->settings->all();
        $defaults = [];
        if ('' === (string) ($current['siteName'] ?? '') && $input->getOption('site-name')) {
            $defaults['siteName'] = (string) $input->getOption('site-name');
        }
        if ('' === (string) ($current['domain'] ?? '') && $input->getOption('domain')) {
            $defaults['domain'] = (string) $input->getOption('domain');
        }
        foreach ($defaults as $k => $v) {
            $this->settings->set($k, $v);
        }
        foreach (['tgOn' => true, 'forms' => true] as $k => $v) {
            if (null === $this->settings->get($k)) {
                $this->settings->set($k, $v);
            }
        }
        $io->writeln([] === $defaults ? I18n::t('ops.install_settings_unchanged') : I18n::t('ops.install_settings_set', ['names' => \implode(', ', \array_keys($defaults))]));
    }
}

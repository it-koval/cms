#!/usr/bin/env bash
# Run inside the php_dev container from the root of a clean Symfony skeleton.
set -euo pipefail

composer config extra.symfony.docker false
composer config repositories.cms '{"type":"path","url":"source","options":{"symlink":false,"versions":{"itk/cms":"0.3.0"}}}'
composer require --no-interaction --no-progress itk/cms

php -r '
    require "vendor/autoload.php";
    $package = json_decode(file_get_contents("source/composer.json"), true, 512, JSON_THROW_ON_ERROR);
    foreach (array_keys($package["suggest"]) as $name) {
        if (Composer\InstalledVersions::isInstalled($name)) {
            throw new RuntimeException("Optional Composer package was installed: " . $name);
        }
    }
'

cp -R source/docker docker
cp docker/compose.prod.yaml compose.prod.yaml

cat > config/routes/itk.yaml <<'YAML'
itk_admin:
    resource: '@ItkCmsBundle/config/routes/admin.php'
itk_site:
    resource: '@ItkCmsBundle/config/routes/site.php'
YAML

cat > config/packages/security.yaml <<'YAML'
security:
    password_hashers:
        Itk\Cms\Entity\User: auto
    providers:
        itk_users:
            id: Itk\Cms\Security\AdminUserProvider
    firewalls:
        itk_mcp:
            pattern: ^/mcp$
            stateless: true
            provider: itk_users
            user_checker: Itk\Cms\Security\UserChecker
            custom_authenticators:
                - Itk\Cms\Mcp\ApiTokenAuthenticator
        itk_admin:
            pattern: ^/admin(/|$)
            provider: itk_users
            user_checker: Itk\Cms\Security\UserChecker
            form_login:
                login_path: itk_admin_login
                check_path: itk_admin_login
                enable_csrf: true
                default_target_path: itk_admin_app
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
            logout:
                path: itk_admin_logout
                target: itk_admin_login
                enable_csrf: true
    access_control:
        - { path: '^/mcp$', roles: ROLE_ITK_USER }
        - { path: '^/admin/(login|forgot-password|reset-password)(/|$)', roles: PUBLIC_ACCESS }
        - { path: '^/admin(/|$)', roles: ROLE_ITK_USER }
YAML

cat > config/packages/itk_cms.yaml <<'YAML'
itk_cms:
    main_locale: en
    public_url: http://127.0.0.1:18080
YAML

mkdir -p src/Smoke templates/pages
cat > templates/pages/home.html.twig <<'TWIG'
<!DOCTYPE html><html lang="en"><head><title>ITK CMS install smoke</title></head><body>ITK CMS install smoke</body></html>
TWIG

cat > src/Smoke/VerifyInstall.php <<'PHP'
<?php

declare(strict_types=1);

namespace App\Smoke;

use Itk\Cms\Content\PageService;
use Itk\Cms\Repository\PageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(name: 'app:verify-install')]
final class VerifyInstall extends Command
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageService $pageService,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $home = $this->pages->findOneBy([]) ?? throw new \RuntimeException('The install did not seed a home page.');
        $this->pageService->publish($home->id);
        $this->bus->dispatch(new WorkerProbe(), [new TransportNamesStamp(['async'])]);

        return self::SUCCESS;
    }
}
PHP

cat > src/Smoke/WorkerProbe.php <<'PHP'
<?php

declare(strict_types=1);

namespace App\Smoke;

final class WorkerProbe {}
PHP

cat > src/Smoke/WorkerProbeHandler.php <<'PHP'
<?php

declare(strict_types=1);

namespace App\Smoke;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WorkerProbeHandler
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir) {}

    public function __invoke(WorkerProbe $probe): void
    {
        if (false === file_put_contents($this->projectDir . '/public/uploads/worker-probe.txt', 'worker received the queued message')) {
            throw new \RuntimeException('Cannot write the worker probe.');
        }
    }
}
PHP

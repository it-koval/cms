<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Itk\Cms\CriticalCss\CssExtractor;
use Itk\Cms\ItkCmsBundle;
use Itk\Cms\KnowledgeBase\Embedding\Embedder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The site the bundle is tested on: the smallest application that has everything Itk\Cms needs (framework, doctrine, migrations, twig, security,
 * messenger with an in-memory async queue). Its project directory is tests/App: config/itk/schema.php, templates/ and the files the tests upload (public/, var/) are there.
 *
 * The database is DATABASE_URL (by default the PostgreSQL of the test stand, 127.0.0.1:33960); the connection uses the database «<name>_test».
 *
 * The admin is at /admin. A test that checks another place for it builds the kernel itself: `$adminPath` is the option itk_cms.admin_path, `$routePrefix` is the prefix
 * of the import of config/routes/admin.php in the site's routes (`prefix: /cp`); the firewall and access_control follow (the site changes them itself, see README).
 * `$framework` replaces top-level blocks of the framework config of the test site (`['messenger' => […]]`: a site without transports, `['notifier' => […]]`: a site with Telegram), see the tests of the install path.
 * `$migrationPaths` is the doctrine_migrations.migrations_paths of the site (namespace => directory): a site that keeps its own migrations.
 * `$itk` replaces keys of the itk_cms config of the test site (`['strict' => false]`, `['schema_file' => …]`).
 *
 * ITK_TEST_CACHE_DIR in the environment moves the cache of the containers out of tests/App/var/cache: runs that go on at the same time on one working tree each keep their own, like their own database.
 */
final class TestKernel extends BaseKernel
{
    use MicroKernelTrait;

    public const DEFAULT_DATABASE_URL = 'postgresql://postgres:itk-test@127.0.0.1:33960/itk?serverVersion=17&charset=utf8';

    /**
     * @param array<string, mixed> $framework top-level blocks of the framework config that replace the ones of the test site
     * @param array<string, string> $migrationPaths migrations of the site: namespace => directory
     * @param array<string, mixed> $itk keys of the itk_cms config that replace the ones of the test site
     * @param int $loginAttempts how many failed sign-ins the limiter of the admin firewall lets through (a test of the lock lowers it)
     */
    public function __construct(string $environment, bool $debug, private readonly ?string $adminPath = null, private readonly string $routePrefix = '', private readonly array $framework = [], private readonly array $migrationPaths = [], private readonly array $itk = [], private readonly int $loginAttempts = 1000)
    {
        parent::__construct($environment, $debug);
    }

    /** Where the admin is served: the prefix of the import and the option. */
    private function mount(): string
    {
        return $this->routePrefix . ($this->adminPath ?? '/admin');
    }

    /** A kernel with its own place for the admin or its own framework blocks has its own cache: the routes and the container differ. */
    public function getCacheDir(): string
    {
        $root = \getenv('ITK_TEST_CACHE_DIR');
        $dir = \is_string($root) && '' !== $root ? \rtrim($root, '/') . '/' . $this->getEnvironment() : parent::getCacheDir();

        return null === $this->adminPath && '' === $this->routePrefix && [] === $this->framework && [] === $this->migrationPaths && [] === $this->itk && 1000 === $this->loginAttempts ? $dir : $dir . '/' . \md5($this->mount() . '|' . $this->adminPath . '|' . \serialize([$this->framework, $this->migrationPaths, $this->itk, $this->loginAttempts]));
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new ItkCmsBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()
            ->set('env(DATABASE_URL)', self::DEFAULT_DATABASE_URL)
            ->set('env(MAILER_DSN)', 'null://null')
            ->set('env(LOCK_DSN)', 'flock');

        $container->extension('framework', $this->framework + [
            'secret' => 'itk-cms-tests',
            'test' => true,
            'profiler' => ['collect' => false], // a test turns it on for one request: $client->enableProfiler()
            'http_method_override' => true,
            'session' => ['name' => 'ITKSESSID', 'storage_factory_id' => 'session.storage.factory.mock_file'],
            'mailer' => ['dsn' => '%env(MAILER_DSN)%'],
            'lock' => '%env(LOCK_DSN)%',
            'router' => ['default_uri' => 'http://localhost'],
            'messenger' => [
                'failure_transport' => 'failed',
                'buses' => ['messenger.bus.default' => null],
                'transports' => [
                    'sync' => 'sync://',
                    'async' => 'in-memory://',
                    'critical_css' => 'in-memory://',
                    'knowledge_base' => 'in-memory://',
                    'failed' => 'doctrine://default?queue_name=failed&auto_setup=0',
                ],
            ],
            // the form limiter of the bundle (itk_request_form) is switched off: the tests send many requests from one address
            'rate_limiter' => ['itk_request_form' => ['policy' => 'no_limit']],
        ]);

        // DoctrineBundle 3 always uses savepoints, lazy ghost objects, generated proxies and reports fields where declared, and no longer has the options for them
        $bundle2 = \version_compare((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '3', '<');
        $savepoints = $bundle2 ? ['use_savepoints' => true] : [];
        $legacyOrm = $bundle2 ? ['auto_generate_proxy_classes' => true, 'enable_lazy_ghost_objects' => true, 'report_fields_where_declared' => true] : [];

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
                'dbname_suffix' => '_test%env(default::TEST_TOKEN)%',
                'schema_filter' => '~^(?!doctrine_migration_versions$)~', // the table of the migrations is not an entity (older doctrine-migrations-bundle does not hide it from schema:validate)
            ] + $savepoints,
            'orm' => $legacyOrm + [
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'controller_resolver' => ['auto_mapping' => false],
            ],
        ]);

        $container->extension('doctrine_migrations', ['enable_profiler' => false] + ([] === $this->migrationPaths ? [] : ['migrations_paths' => $this->migrationPaths]));

        $container->extension('twig', ['file_name_pattern' => '*.twig', 'strict_variables' => true]);

        $mount = $this->mount();

        // the setup of README, step 4
        $container->extension('security', [
            'password_hashers' => [
                'Itk\Cms\Entity\User' => ['algorithm' => 'auto', 'cost' => 4, 'time_cost' => 3, 'memory_cost' => 10], // the cheapest hashes: the tests make many users
            ],
            'providers' => ['itk_users' => ['id' => 'Itk\Cms\Security\AdminUserProvider']],
            'firewalls' => [
                'itk_mcp' => [
                    'pattern' => '^/mcp$',
                    'stateless' => true,
                    'provider' => 'itk_users',
                    'user_checker' => 'Itk\Cms\Security\UserChecker',
                    'custom_authenticators' => ['Itk\Cms\Mcp\ApiTokenAuthenticator'],
                ],
                'itk_admin' => [
                    'pattern' => '^' . $mount . '(/|$)',
                    'provider' => 'itk_users',
                    'user_checker' => 'Itk\Cms\Security\UserChecker',
                    'form_login' => [
                        'login_path' => 'itk_admin_login',
                        'check_path' => 'itk_admin_login',
                        'enable_csrf' => true,
                        'default_target_path' => 'itk_admin_app',
                    ],
                    'login_throttling' => ['max_attempts' => $this->loginAttempts, 'interval' => '15 minutes'], // the tests sign in over and over
                    'logout' => ['path' => 'itk_admin_logout', 'target' => 'itk_admin_login', 'enable_csrf' => true],
                ],
            ],
            'access_control' => [
                ['path' => '^/mcp$', 'roles' => 'ROLE_ITK_USER'],
                ['path' => '^' . $mount . '/(login|forgot-password|reset-password)', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^' . $mount . '(/|$)', 'roles' => 'ROLE_ITK_USER'],
            ],
        ]);

        $container->extension('itk_cms', $this->itk + [
            'schema_file' => '%kernel.project_dir%/config/schema.php',
            'admin_locale' => 'ru',
            'upload_dir' => '%kernel.project_dir%/public/uploads/media',
            'images' => ['ratios' => ['16:9', '4:3', '1:1', '3:4', '14:5', '17:8']],
        ] + (null === $this->adminPath ? [] : ['admin_path' => $this->adminPath]));

        // the classes of the test site: templates, collections and settings of tests/App/Itk
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('Itk\Cms\Tests\App\Itk\\', __DIR__ . '/Itk/');

        // what the code logs on purpose (failed letters, 404 of image sizes, an MCP error) is checked by the tests, not printed into their output
        $services->set('logger', RecordingLogger::class)->public();

        // the queries that reach the database are counted (QueryCounter::during()), for the tests that say «not one query per page»
        $services->set(QueryCounter::class)->tag('doctrine.middleware');

        $services->set(FakeCssExtractor::class)->public();
        $services->alias(CssExtractor::class, FakeCssExtractor::class);
        $services->set(FakeEmbedder::class)->public();
        $services->alias(Embedder::class, FakeEmbedder::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $admin = $routes->import('@ItkCmsBundle/config/routes/admin.php');
        if ('' !== $this->routePrefix) {
            $admin->prefix($this->routePrefix);
        }
        $routes->import('@ItkCmsBundle/config/routes/site.php'); // requests and the catch-all page route (priority -1000)
    }
}

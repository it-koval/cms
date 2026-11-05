<?php

declare(strict_types=1);

use Itk\Cms\Controller\Admin\AdminAppController;
use Itk\Cms\Controller\Admin\TwoFactorLoginController;
use Itk\Cms\Doctrine\MigrationVersionComparator;
use Itk\Cms\Health\KnowledgeBaseHealthCheck;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\KnowledgeBase\Embedding\Embedder;
use Itk\Cms\KnowledgeBase\Embedding\OpenAiEmbedder;
use Itk\Cms\KnowledgeBase\KnowledgeSearch;
use Itk\Cms\Logging\SecretScrubProcessor;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaLoader;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Itk\\Cms\\', '../src/')
        ->exclude([
            '../src/ItkCmsBundle.php',
            '../src/I18n/I18n.php',
            '../src/{Entity,Migrations,Support,Logging,Field,Attribute,Event,Doctrine}',
            '../src/Resource/{Action,ActionContext,ActionResult,Context,Metric,Record,ResourceApplier,ResourceHooks}.php',
            '../src/Extension/NavItem.php',
            '../src/Generator/{GeneratedFile,FieldSpec,CollectionSpec,Naming}.php',
            '../src/Content/ImportResult.php',
            '../src/Security/Viewer.php',
            '../src/Schema/{Field,Key,Template,SectionType,Collection,MenuAreaDef,SettingsGroup,FormDef,Schema,SchemaBuilder,CoreSchema}.php',
            '../src/Security/Permission.php',
            '../src/Content/ContentException.php',
            '../src/Request/{SendRequestNotification,RequestNotDeliveredException}.php',
            '../src/Media/GenerateImageVariants.php',
            '../src/Notification/Notification.php',
            '../src/Health/HealthResult.php',
            '../src/Mail/{MailConfig,MailRoute,MailDeliveryException,SendMail}.php',
            '../src/Security/RequestPasswordLink.php',
            '../src/Mcp/{McpScopes,McpToolException,McpProtocolError,ApiTokenException,ArgumentChecker,McpContext}.php',
            '../src/CriticalCss/Model',
            '../src/KnowledgeBase/Model',
            '../src/KnowledgeBase/Embedding/{Space,Vector,Purpose,Embeddings,EmbeddingFailed}.php',
        ]);

    // the embedder every part of the knowledge base gets; a site may alias Embedder to its own implementation, the test site aliases it to a fake.
    // The HTTP client is optional (symfony/http-client is only suggested), as for UpdateChecker
    $services->alias(Embedder::class, OpenAiEmbedder::class);
    $services->get(OpenAiEmbedder::class)->arg('$http', service('http_client')->nullOnInvalid());
    $services->get(KnowledgeBaseHealthCheck::class)->arg('$http', service('http_client')->nullOnInvalid());

    // the one class of the knowledge base that site code calls (a site search, a chatbot); public so that a site can also take it from a service locator
    $services->get(KnowledgeSearch::class)->public();

    // the one class of the page cache that site code calls (clear() after its own import); public so that a site can also take it from a service locator
    $services->get(PageCache::class)->public();

    // the bundle hands it to the facade Itk\Cms\I18n\I18n when it boots; a site with framework.translator off has no switcher (the facade then reads the files of the bundle)
    $services->get(AdminLocale::class)->public();

    // the bot token never reaches a log (HttpClient logs the Telegram URL with it); only when Monolog is installed
    if (\interface_exists(ProcessorInterface::class)) {
        $services->set(SecretScrubProcessor::class)->tag('monolog.processor');
    }

    // migrations run in the order of the date in their class name (the bundle prepends it to doctrine_migrations)
    $services->set('itk_cms.migration_version_comparator', MigrationVersionComparator::class);

    // the site schema is read once per request from config/itk/schema.php
    $services->set(Schema::class)->factory([service(SchemaLoader::class), 'load']);

    // Telegram goes through the site's Notifier chatter and is optional: a closure, so that TELEGRAM_DSN is read only when a request is sent (a variable nobody set
    // must not break every controller that needs this class); without a chatter requests are saved and the delivery stays «pending»
    $services->get(TelegramNotifier::class)->arg('$chatter', service_closure('chatter.transports')->nullOnInvalid());

    // «Состояние сайта»: every transport of the site and its failure transport (none when the site has no failure_transport; built on first use, so one that cannot be built does not break the screen)
    $services->get(MessengerMonitor::class)
        ->arg('$receivers', service('messenger.receiver_locator'))
        ->arg('$failed', service_closure('messenger.failure_transports.default')->nullOnInvalid());

    // the logout link carries the firewall's CSRF token (logout.enable_csrf); the generator is not autowirable
    $services->get(AdminAppController::class)->arg('$logoutUrls', service('security.logout_url_generator')->nullOnInvalid());
    $services->get(TwoFactorLoginController::class)->arg('$logoutUrls', service('security.logout_url_generator')->nullOnInvalid());
};

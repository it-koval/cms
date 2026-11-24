<?php

declare(strict_types=1);

namespace Itk\Cms;

use Doctrine\Migrations\Version\Comparator;
use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Admin\Theme\Color;
use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\Content\PageService;
use Itk\Cms\Controller\Site\RequestSubmitController;
use Itk\Cms\Doctrine\JsonFirstValue;
use Itk\Cms\Doctrine\JsonGet;
use Itk\Cms\Doctrine\JsonNumber;
use Itk\Cms\Doctrine\JsonText;
use Itk\Cms\Doctrine\PhoneDigits;
use Itk\Cms\Doctrine\VectorType;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Request\RequestRetryStrategy;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * ITK CMS: pages made of sections, collections, menus, settings, media, requests, admin API.
 *
 * The site describes its content in config/itk/schema.php (see Itk\Cms\Schema\SchemaBuilder).
 */
final class ItkCmsBundle extends AbstractBundle
{
    protected string $extensionAlias = 'itk_cms';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The bundle's controllers are imported explicitly by config/routes/{admin,site}.php (the admin ones with the prefix itk_cms.admin_path, /admin by default).
        // Without this the Symfony recipe entry `controllers: resource: routing.controllers` would import them a second time, unprefixed.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->findTaggedServiceIds('routing.controller') as $id => $_) {
                    $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass());
                    if (\is_string($class) && \str_starts_with($class, 'Itk\\Cms\\Controller\\')) {
                        $container->getDefinition($id)->clearTag('routing.controller');
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);

        // Keep the application's default locale in consoles and workers. The interface listener scopes the admin language to CMS requests.
        // Append the bundle fallback after the site's own fallback languages.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition('translator.default')) {
                    $translator = $container->getDefinition('translator.default');
                    $calls = $translator->getMethodCalls();
                    foreach ($calls as $i => [$method, $arguments]) {
                        if ('setFallbackLocales' === $method && !\in_array(I18n::FALLBACK, $arguments[0], true)) {
                            $calls[$i][1][0][] = I18n::FALLBACK;
                        }
                    }
                    $translator->setMethodCalls($calls);
                }
            }
        });

        // The description of a command is a constant in #[AsCommand], so there it is a key of the catalogue (ops.cmd_doctor_description): this pass makes it the text in the language
        // of the console when the command is built, both in the LazyCommand of `list` and in the command itself. A description that is not a key stays as it is.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            private const KEY = '/^[a-z0-9_]+\.cmd_[a-z0-9_]+$/';

            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $definition) {
                    $description = $definition->getArguments()[2] ?? null;
                    if (LazyCommand::class === $definition->getClass() && \is_string($description) && 1 === \preg_match(self::KEY, $description)) {
                        $definition->replaceArgument(2, $this->text($description));
                    }
                    if (!$definition->hasTag('console.command')) {
                        continue;
                    }
                    $calls = $definition->getMethodCalls();
                    foreach ($calls as $i => [$method, $arguments]) {
                        if ('setDescription' === $method && \is_string($arguments[0] ?? null) && 1 === \preg_match(self::KEY, $arguments[0])) {
                            $calls[$i][1][0] = $this->text($arguments[0]);
                        }
                    }
                    $definition->setMethodCalls($calls);
                }
            }

            private function text(string $key): Definition
            {
                return (new Definition('string'))->setFactory([I18n::class, 't'])->setArguments([$key]);
            }
        }, PassConfig::TYPE_BEFORE_REMOVING, -10);
    }

    /** The facade of the translations gets the translator of this kernel: nothing is built until the first text is asked for. */
    public function boot(): void
    {
        $container = $this->container;
        if (null !== $container) {
            I18n::boot(static function () use ($container): AdminLocale {
                $locale = $container->get(AdminLocale::class);
                \assert($locale instanceof AdminLocale);

                return $locale;
            });
        }
    }

    public function shutdown(): void
    {
        I18n::shutdown();
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->validate()
                ->ifTrue(static fn (array $v): bool => !\in_array($v['admin_locale'], $v['admin_locales'], true))
                ->then(static function (array $v): array {
                    throw new InvalidConfigurationException(\sprintf('itk_cms.admin_locale "%s" is not one of itk_cms.admin_locales (%s).', \is_scalar($v['admin_locale']) ? $v['admin_locale'] : \gettype($v['admin_locale']), \implode(', ', $v['admin_locales'])));
                })
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => null !== $v['locales'])
                ->then(static function (array $v): array {
                    if ([] === $v['locales']) {
                        throw new InvalidConfigurationException('itk_cms.locales must list at least the main language');
                    }
                    $reserved = [];
                    foreach ([...\array_keys(PageService::RESERVED_URLS), '/bundles'] as $path) {
                        $segment = \explode('/', \ltrim($path, '/'), 2)[0];
                        if (!\str_contains($segment, '.')) {
                            $reserved[$segment] = $path;
                        }
                    }
                    $admin = \strtolower(\explode('/', \ltrim($v['admin_path'], '/'), 2)[0]);
                    foreach (\array_keys($v['locales']) as $code) {
                        if (isset($reserved[$code])) {
                            throw new InvalidConfigurationException(\sprintf('Language code "%s" in itk_cms.locales conflicts with reserved path "%s".', $code, $reserved[$code]));
                        }
                        if ($code === $admin) {
                            throw new InvalidConfigurationException(\sprintf('Language code "%s" in itk_cms.locales conflicts with itk_cms.admin_path "%s".', $code, $v['admin_path']));
                        }
                        if (1 !== \preg_match('~^[a-z]{2,3}\z~', (string) $code)) {
                            throw new InvalidConfigurationException(\sprintf('Invalid language code "%s" in itk_cms.locales. Use 2 or 3 lowercase letters.', $code));
                        }
                    }
                    $main = \strtolower(\trim((string) $v['main_locale']));
                    if (!isset($v['locales'][$main])) {
                        throw new InvalidConfigurationException(\sprintf('itk_cms.main_locale "%s" is not one of itk_cms.locales (%s).', $main, \implode(', ', \array_keys($v['locales']))));
                    }
                    $v['main_locale'] = $main;

                    return $v;
                })
            ->end()
            ->children()
                ->scalarNode('schema_file')
                    ->info('PHP file that returns static function (SchemaBuilder $s): void')
                    ->defaultValue('%kernel.project_dir%/config/itk/schema.php')
                ->end()
                ->scalarNode('main_locale')->defaultValue('ru')->end()
                ->arrayNode('locales')
                    ->info('Languages of the site content, keyed by their short codes')
                    ->useAttributeAsKey('code')
                    ->normalizeKeys(false)
                    ->defaultNull()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')
                                ->isRequired()
                                ->validate()->ifTrue(static fn ($v): bool => !\is_string($v) || '' === \trim($v))->thenInvalid('Language name must be a non-empty string')->end()
                            ->end()
                            ->scalarNode('html_lang')
                                ->defaultNull()
                                ->validate()->ifTrue(static fn ($v): bool => null !== $v && (!\is_string($v) || 1 !== \preg_match('~^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\z~', $v)))->thenInvalid('html_lang must be a language tag like en or zh-CN')->end()
                            ->end()
                            ->scalarNode('og_locale')
                                ->defaultNull()
                                ->validate()->ifTrue(static fn ($v): bool => null !== $v && (!\is_string($v) || 1 !== \preg_match('~^[a-z]{2,3}_[A-Z]{2}\z~', $v)))->thenInvalid('og_locale must be a language and country separated by an underscore, like zh_CN')->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->always()
                        ->then(static function (array $locales): array {
                            $tags = [];
                            foreach ($locales as $code => $locale) {
                                $tag = $locale['html_lang'] ?? (string) $code;
                                $key = \strtolower($tag);
                                if (isset($tags[$key])) {
                                    throw new InvalidConfigurationException(\sprintf('Languages "%s" and "%s" in itk_cms.locales have the same html_lang "%s".', $tags[$key], $code, $tag));
                                }
                                $tags[$key] = $code;
                            }

                            return $locales;
                        })
                    ->end()
                ->end()
                ->scalarNode('admin_locale')
                    ->info('Language of the admin panel, of its letters and of the console for whoever has not chosen his own in "My profile". This is the language of the interface: the languages of the content are main_locale and the language versions of pages')
                    ->defaultValue('en')
                ->end()
                ->arrayNode('admin_locales')
                    ->info('The languages a person may choose for the admin panel, out of the built-in ones: ' . \implode(', ', \array_keys(I18n::LOCALES)))
                    ->performNoDeepMerging()
                    ->requiresAtLeastOneElement()
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray(\array_keys(I18n::LOCALES))
                            ->thenInvalid('The admin panel is not translated into %s. Built-in languages: ' . \implode(', ', \array_keys(I18n::LOCALES)))
                        ->end()
                    ->end()
                    ->defaultValue(\array_keys(I18n::LOCALES))
                ->end()
                ->scalarNode('admin_path')
                    ->info('Where the admin stands: /admin (default), /cp, /site/panel. One or more segments, starts with / and has no trailing one. The firewall `itk_admin` and its access_control lines in security.yaml are changed by the site itself')
                    ->defaultValue('/admin')
                    ->validate()->ifTrue(static fn ($v): bool => !\is_string($v) || 1 !== \preg_match('~^(?:/[A-Za-z0-9_-]+)+$~', $v))->thenInvalid('A path like /admin or /site/panel is expected: one or more segments of letters, digits, - and _, starting with / and without a trailing /')->end()
                ->end()
                ->booleanNode('strict')
                    ->info('A mistake of a site template (a key that the section has no field for in itk_field(), a section type the schema does not know, a section without its Twig template) is an exception that lists what exists; false = a warning in the log. By default: %kernel.debug%')
                    ->defaultNull()
                ->end()
                ->booleanNode('security_headers')
                    ->info('X-Content-Type-Options: nosniff and Referrer-Policy on the public routes of the bundle (live pages, sitemap, robots.txt, llms.txt, image copies, forms, /healthz); false = the site sets its own')
                    ->defaultTrue()
                ->end()
                ->booleanNode('conditional_responses')
                    ->info('ETag and Last-Modified on live pages for a visitor without a session, and 304 when the page has not changed (the tag follows the page, the live pages, settings, menus, collections and SEO rules); never in debug')
                    ->defaultTrue()
                ->end()
                ->arrayNode('analytics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('start')
                            ->info('When to start the configured analytics counters: auto on page load, or manual through itkAnalytics.start() in the site frontend')
                            ->values([AnalyticsConfig::AUTO, AnalyticsConfig::MANUAL])
                            ->defaultValue(AnalyticsConfig::AUTO)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('page_cache')
                    ->info('The finished HTML of live pages, kept so that a repeated visit is answered without a render. The key follows the content (the same fingerprint as the ETag), so an edit shows at once; never in debug, for a visitor with a session or with Authorization')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('ttl')->info('Seconds an entry lives at most (a template shortens it with itk_cache()); it limits what the fingerprint cannot see, such as the tables of the site')->defaultValue(3600)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('critical_css')
                    ->info('Critical CSS of live pages: made by crittr in the worker after each publish, inlined by itk_stylesheets(); needs Node 22+ and `npm install crittr` in the project')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['enabled'] && [] === $v['stylesheets'])
                        ->thenInvalid('critical_css.stylesheets must list at least one stylesheet when critical_css is enabled')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('stylesheets')
                            ->info('Public paths of the site\'s stylesheets in cascade order (/css/site.css): itk_stylesheets() links them, the generator reads them from public/')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn ($v): bool => !\is_string($v) || 1 !== \preg_match('~^/(?!/)[^?#\s]*\.css$~', $v) || \str_contains($v, '..'))
                                    ->thenInvalid('A public path like /css/site.css is expected (no scheme, no query string, no ..)')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('viewports')
                            ->info('One crittr run per viewport; its CSS is inlined for screens from its width up to the next one\'s. Put one at each breakpoint of the layout')
                            ->arrayPrototype()
                                ->children()
                                    ->integerNode('width')->isRequired()->min(240)->max(3840)->end()
                                    ->integerNode('height')->isRequired()->min(320)->max(2160)->end()
                                    ->booleanNode('mobile')->info('Measure as a phone: touch and a mobile user agent')->defaultFalse()->end()
                                ->end()
                            ->end()
                            ->requiresAtLeastOneElement()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => \count(\array_unique(\array_column($v, 'width'))) !== \count($v))
                                ->thenInvalid('Two viewports have the same width')
                            ->end()
                            ->defaultValue([['width' => 390, 'height' => 844, 'mobile' => true], ['width' => 1366, 'height' => 900, 'mobile' => false]])
                        ->end()
                        ->arrayNode('keep_selectors')->info('Selectors crittr keeps in the critical CSS whatever the first screen shows (passed as is)')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('remove_selectors')->info('Selectors crittr leaves out of the critical CSS (passed as is)')->scalarPrototype()->end()->defaultValue([])->end()
                        ->scalarNode('node')->info('The Node 22+ binary the worker runs')->defaultValue('node')->end()
                        ->scalarNode('chrome')->info('Path of Chrome or Chromium; null = PUPPETEER_EXECUTABLE_PATH if set, else the one puppeteer downloaded for the user who ran npm install')->defaultNull()->end()
                        ->integerNode('timeout')->info('Seconds for one page in one viewport; the Node process gets this times pages times viewports')->defaultValue(60)->min(10)->end()
                        ->integerNode('max_kb')->info('Critical CSS of one viewport, or the merged block, above this many kilobytes is not inlined: the page is served with the plain link')->defaultValue(75)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('knowledge_base')
                    ->info('A pgvector index of the live site\'s text, kept in step with every publish by the worker (transport knowledge_base) and searched by KnowledgeBase\KnowledgeSearch; needs OPENAI_API_KEY and symfony/http-client')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('model')
                            ->info('An OpenAI embedding model; text-embedding-3-* are stored at 1536 dimensions. Changing it makes every document stale: run itk:knowledge-base:sync --now')
                            ->defaultValue('text-embedding-3-small')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('base_url')->info('An OpenAI-compatible API (a proxy, Azure OpenAI)')->defaultValue('https://api.openai.com/v1')->cannotBeEmpty()->end()
                        ->integerNode('timeout')->info('Seconds for one embedding request')->defaultValue(30)->min(5)->end()
                        ->arrayNode('exclude_templates')->info('Page templates that are never indexed')->scalarPrototype()->end()->defaultValue([])->end()
                        ->arrayNode('exclude_collections')->info('Collections whose items are never indexed')->scalarPrototype()->end()->defaultValue([])->end()
                    ->end()
                ->end()
                ->arrayNode('indexnow')
                    ->info('IndexNow: the pings that tell search engines about published, moved and unpublished pages (the toggle is in the settings group analytics)')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('endpoint')->info('The IndexNow endpoint the worker posts to; a search engine shares the pings with the others')->defaultValue('https://yandex.com/indexnow')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('yandex_webmaster')
                    ->info('Yandex Webmaster on the screen Today: the owner pastes an OAuth token once, the CMS adds the site, checks ownership with a meta tag, registers sitemap.xml and shows how the site does in Yandex')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('client_id')->info('The ID of the OAuth application registered at oauth.yandex.ru (scopes webmaster:hostinfo and webmaster:verify): the admin offers the owner a link that gives the token. Empty = no link, the owner brings a token himself')->defaultValue('%env(string:default::ITK_YANDEX_CLIENT_ID)%')->end()
                        ->scalarNode('api_base')->info('The address of the Webmaster API; a site changes it only to point a demo or a test at a stand-in')->defaultValue('https://api.webmaster.yandex.net/v4')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->booleanNode('blog')
                    ->info('false = a site without a blog: the templates blog, blog_category and blog_article are not registered and the "Blog articles" link is not in the rail (the same as $s->withoutBlog() in schema.php)')
                    ->defaultTrue()
                ->end()
                ->scalarNode('upload_dir')->defaultValue('%kernel.project_dir%/public/uploads/media')->end()
                ->scalarNode('upload_url')->defaultValue('/uploads/media')->end()
                ->integerNode('max_upload_mb')->defaultValue(20)->min(1)->end()
                ->arrayNode('requests')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_body')
                            ->info('Largest public form submission body, in bytes')
                            ->defaultValue(RequestSubmitController::DEFAULT_MAX_BODY)->min(1)->max(\PHP_INT_MAX - 1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('images')
                    ->info('Resized WebP variants of uploaded images: /uploads/media/_v/w{width}/Y/m/name.webp, made on the first request and kept as static files')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('widths')
                            ->info('The only widths a variant may have (anything else is 404, so nobody can fill the disk)')
                            ->integerPrototype()->min(16)->max(8192)->end()
                            ->defaultValue([320, 640, 960, 1280, 1920, 2560])
                            ->requiresAtLeastOneElement()
                        ->end()
                        ->integerNode('quality')->defaultValue(82)->min(1)->max(100)->end()
                        ->integerNode('max_pixels')->info('Originals above this many pixels are not converted (decompression bomb guard); the original is served')->defaultValue(50_000_000)->min(1)->end()
                        ->integerNode('avif_quality')->info('Quality of the AVIF copies (AVIF looks the same as WebP at a lower number)')->defaultValue(60)->min(1)->max(100)->end()
                        ->arrayNode('ratios')
                            ->info('Ratios an image may be cut to (the media editor offers them, itk_media_url(img, w, "16:9") and itk_img(img, {ratio}) accept them); a frame saved for a file is allowed on top of these')
                            ->scalarPrototype()->end()
                            ->defaultValue(['16:9', '4:3', '1:1', '3:4'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('admin')
                    ->info('The admin panel itself')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('theme')
                            ->info('Colours and corners of the admin; an administrator can override them in Settings → Admin panel appearance')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('brand')
                                    ->info('Main colour (the side rail, buttons, links), a hex like #0B5D80')
                                    ->defaultValue('#0B5D80')
                                    ->validate()->ifTrue(static fn ($v): bool => !\is_string($v) || null === Color::normalize($v))->thenInvalid('A colour like #0B5D80 is expected')->end()
                                ->end()
                                ->scalarNode('accent')
                                    ->info('Accent colour (marks of unsaved changes, counters), a hex like #C8217A')
                                    ->defaultValue('#C8217A')
                                    ->validate()->ifTrue(static fn ($v): bool => !\is_string($v) || null === Color::normalize($v))->thenInvalid('A colour like #C8217A is expected')->end()
                                ->end()
                                ->integerNode('radius')->info('Corner radius of buttons and fields in px (cards get 4px more)')->defaultValue(6)->min(0)->max(16)->end()
                                ->scalarNode('logo')
                                    ->info('Logo of the admin: a site path (/build/logo.svg) or https:// address; null = the built-in mark')
                                    ->defaultNull()
                                    ->validate()->ifTrue(static fn ($v): bool => null !== $v && (!\is_string($v) || 1 !== \preg_match('~^(/(?!/)|https://)[^\s"\'<>\\\\]+$~', $v)))->thenInvalid('A site path starting with / or an https:// address is expected')->end()
                                ->end()
                                ->scalarNode('name')
                                    ->info('Product name in the header of the admin instead of «ITK CMS» (up to ' . AdminTheme::MAX_NAME . ' characters, one line of plain text); null or an empty one = ITK CMS; may be an %env()% placeholder (its value is checked when it is read, a wrong one counts as no option)')
                                    ->defaultNull()
                                    ->validate()->ifTrue(static fn ($v): bool => null !== $v && (!\is_string($v) || \mb_strlen(\trim($v)) > AdminTheme::MAX_NAME || 1 === \preg_match('/[\x00-\x1F\x7F]/', $v)))->thenInvalid('One line of text, up to ' . AdminTheme::MAX_NAME . ' characters, is expected')->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('history')
                    ->info('History of changes of pages, records, settings and menus (itk:history:prune drops what is beyond these limits)')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('keep')->info('Entries kept for each page, record, group of settings or menu in each language')->defaultValue(100)->min(1)->end()
                        ->integerNode('days')->info('Entries older than this many days are dropped (the newest entry of an object that still exists stays)')->defaultValue(365)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('mcp')
                    ->info('The MCP server for AI assistants (POST /mcp, itk:mcp). It is switched on by an administrator in Settings → AI assistants; these are its technical limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_origins')
                            ->info('Origins let through when a program sends an Origin header with its call, e.g. https://inspector.example (the check against a web page of another site that tries to call /mcp). This is not CORS: a browser page of another site still cannot call /mcp, because /mcp answers no preflight. The address of the site itself (PublicUrl) is always allowed, localhost only in debug mode. Programs that send no Origin need nothing here')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('max_body_mb')->info('Largest request body of /mcp in megabytes (a base64 picture of media_upload is the biggest thing in it)')->defaultValue(8)->min(1)->max(64)->end()
                    ->end()
                ->end()
                ->scalarNode('public_url')
                    ->info('Full site origin for password and invitation links, e.g. https://example.com. May be an %env()% placeholder. Ordinary site links can also use the request host checked by framework.trusted_hosts.')
                    ->defaultNull()
                    ->validate()->ifTrue(static fn ($v): bool => null !== $v && !\is_string($v))->thenInvalid('An address like https://example.com is expected')->end()
                ->end()
                ->scalarNode('update_source')
                    ->info('Address that answers JSON with the latest version of itk/cms ({"version": "1.4.0"}, GitHub releases/latest or Packagist metadata); "Site status" checks it once in 12 hours. Off by default.')
                    ->defaultNull()
                ->end()
                ->arrayNode('telegram')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('chat_ids')
                            ->info('Fallback chat ids (comma separated) when Settings → Notifications has no chat for the form. May be an %env()% placeholder.')
                            ->defaultValue('')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('max')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('chat_ids')
                            ->info('Fallback MAX chat ids (comma separated, whole numbers) when Settings → Notifications has no MAX chat for the form. May be an %env()% placeholder. Needs yuri-kovalev/max-bot-symfony-bundle with its access token.')
                            ->defaultValue('')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = $container->parameters();
        $parameters->set('itk_cms.schema_file', $config['schema_file']);
        $mainLocale = (string) $config['main_locale'];
        $locales = $config['locales'];
        $main = $locales[$mainLocale] ?? ['name' => (new ContentLocales($mainLocale))->name($mainLocale), 'html_lang' => null, 'og_locale' => null];
        $parameters->set('itk_cms.main_locale', $mainLocale);
        $parameters->set('itk_cms.locales', [$mainLocale => $main] + ($locales ?? []));
        $parameters->set('itk_cms.locales_configured', null !== $locales);
        $parameters->set('itk_cms.admin_locale', $config['admin_locale']);
        $parameters->set('itk_cms.admin_locales', \array_values(\array_unique($config['admin_locales'])));
        $parameters->set('itk_cms.blog', $config['blog']);
        $parameters->set('itk_cms.security_headers', $config['security_headers']);
        $parameters->set('itk_cms.conditional_responses', $config['conditional_responses']);
        $parameters->set('itk_cms.analytics.start', $config['analytics']['start']);
        $parameters->set('itk_cms.page_cache.enabled', $config['page_cache']['enabled']);
        $parameters->set('itk_cms.page_cache.ttl', $config['page_cache']['ttl']);
        $critical = $config['critical_css'];
        $viewports = $critical['viewports'];
        \usort($viewports, static fn (array $a, array $b): int => $a['width'] <=> $b['width']);
        $parameters->set('itk_cms.critical_css.enabled', $critical['enabled']);
        $parameters->set('itk_cms.critical_css.stylesheets', \array_values($critical['stylesheets']));
        $parameters->set('itk_cms.critical_css.viewports', $viewports);
        $parameters->set('itk_cms.critical_css.keep_selectors', \array_values(\array_map('strval', $critical['keep_selectors'])));
        $parameters->set('itk_cms.critical_css.remove_selectors', \array_values(\array_map('strval', $critical['remove_selectors'])));
        $parameters->set('itk_cms.critical_css.node', (string) $critical['node']);
        $parameters->set('itk_cms.critical_css.chrome', null === $critical['chrome'] ? null : (string) $critical['chrome']);
        $parameters->set('itk_cms.critical_css.timeout', $critical['timeout']);
        $parameters->set('itk_cms.critical_css.max_kb', $critical['max_kb']);
        $kb = $config['knowledge_base'];
        $parameters->set('itk_cms.knowledge_base.enabled', $kb['enabled']);
        $parameters->set('itk_cms.knowledge_base.model', (string) $kb['model']);
        $parameters->set('itk_cms.knowledge_base.base_url', \rtrim((string) $kb['base_url'], '/'));
        $parameters->set('itk_cms.knowledge_base.timeout', $kb['timeout']);
        $parameters->set('itk_cms.knowledge_base.exclude_templates', \array_values(\array_map('strval', $kb['exclude_templates'])));
        $parameters->set('itk_cms.knowledge_base.exclude_collections', \array_values(\array_map('strval', $kb['exclude_collections'])));
        $parameters->set('itk_cms.indexnow.endpoint', (string) $config['indexnow']['endpoint']);
        $parameters->set('itk_cms.yandex_webmaster.client_id', (string) $config['yandex_webmaster']['client_id']);
        $parameters->set('itk_cms.yandex_webmaster.api_base', (string) $config['yandex_webmaster']['api_base']);
        $parameters->set('itk_cms.strict', $config['strict'] ?? (bool) $builder->getParameter('kernel.debug'));
        $parameters->set('itk_cms.admin_path', $config['admin_path']);
        $parameters->set('itk_cms.admin_path_segments', \ltrim($config['admin_path'], '/')); // for the prefix of config/routes/admin.php, which adds the slash itself
        $parameters->set('itk_cms.upload_dir', $config['upload_dir']);
        $parameters->set('itk_cms.upload_url', $config['upload_url']);
        $parameters->set('itk_cms.max_upload_mb', $config['max_upload_mb']);
        $parameters->set('itk_cms.requests.max_body', $config['requests']['max_body']);
        $widths = \array_values(\array_unique(\array_map('intval', $config['images']['widths'])));
        \sort($widths);
        $parameters->set('itk_cms.images.widths', $widths);
        $parameters->set('itk_cms.images.quality', $config['images']['quality']);
        $parameters->set('itk_cms.images.max_pixels', $config['images']['max_pixels']);
        $parameters->set('itk_cms.images.avif_quality', $config['images']['avif_quality']);
        $ratios = [];
        foreach ($config['images']['ratios'] as $ratio) {
            $pair = ImageVariants::parseRatio($ratio);
            if (null !== $pair) {
                $ratios[ImageVariants::ratioKey($pair)] = ImageVariants::ratioKey($pair);
            }
        }
        $parameters->set('itk_cms.images.ratios', \array_values($ratios));
        $theme = $config['admin']['theme'];
        $parameters->set('itk_cms.admin.theme', ['brand' => Color::normalize($theme['brand']), 'accent' => Color::normalize($theme['accent']), 'radius' => $theme['radius'], 'logo' => $theme['logo'], 'name' => $theme['name']]);
        $parameters->set('itk_cms.mcp.allowed_origins', \array_values(\array_filter(\array_map('strval', $config['mcp']['allowed_origins']))));
        $parameters->set('itk_cms.mcp.max_body_mb', $config['mcp']['max_body_mb']);
        $parameters->set('itk_cms.history.keep', $config['history']['keep']);
        $parameters->set('itk_cms.history.days', $config['history']['days']);
        $parameters->set('itk_cms.update_source', $config['update_source']);
        $publicUrl = $config['public_url'];
        if (null !== $publicUrl) {
            $envs = [];
            $builder->resolveEnvPlaceholders($publicUrl, null, $envs);
            if ([] === $envs && !PublicUrl::isValid($publicUrl)) { // a literal is checked now, a %env()% placeholder when PublicUrl reads it
                throw new InvalidConfigurationException('Invalid configuration for path "itk_cms.public_url": An address like https://example.com is expected');
            }
        }
        $parameters->set('itk_cms.public_url', null === $publicUrl ? null : \rtrim($publicUrl, '/'));
        $parameters->set('itk_cms.telegram.enabled', $config['telegram']['enabled']);
        $parameters->set('itk_cms.telegram.chat_ids', $config['telegram']['chat_ids']);
        $parameters->set('itk_cms.max.enabled', $config['max']['enabled']);
        $parameters->set('itk_cms.max.chat_ids', $config['max']['chat_ids']);

        $container->import('../config/services.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $path = $this->getPath();

        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'dbal' => [
                    'types' => [VectorType::NAME => VectorType::class],
                    'mapping_types' => [VectorType::NAME => VectorType::NAME],
                ],
                'orm' => [
                    'dql' => [
                        'string_functions' => ['JSON_TEXT' => JsonText::class, 'JSON_GET' => JsonGet::class, 'JSON_FIRST_VALUE' => JsonFirstValue::class, 'ITK_PHONE_DIGITS' => PhoneDigits::class],
                        'numeric_functions' => ['JSON_NUMBER' => JsonNumber::class],
                    ],
                    'mappings' => [
                        'ItkCms' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => $path . '/src/Entity',
                            'prefix' => 'Itk\\Cms\\Entity',
                            'alias' => 'ItkCms',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            // doctrine:migrations:diff and :generate write into the FIRST path: a prepended config would put the bundle (vendor) before the site, so the paths the site
            // already named are copied in front of it, in its own order (the site's values are merged over the copies anyway)
            $paths = [];
            foreach ($builder->getExtensionConfig('doctrine_migrations') as $config) {
                foreach ((array) ($config['migrations_paths'] ?? []) as $namespace => $dir) {
                    $paths[(string) $namespace] = $dir;
                }
            }
            $paths += ['Itk\\Cms\\Migrations' => $path . '/src/Migrations'];
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => $paths,
                // by the date in the class name, so site migrations that use bundle tables run after the bundle migrations that create them
                'services' => [Comparator::class => 'itk_cms.migration_version_comparator'],
            ]);
        }

        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', [
                // a site without a translator of its own still gets the catalogues of the bundle (translations/itk_*.yaml); its translations/ folder overrides them, domain by domain
                'translator' => ['enabled' => true],
                // the rate limiters (login, forms, MCP) take a lock around each count; without it parallel calls would all pass.
                // The default store is a file lock of this server; for several servers the site sets `framework.lock` to a shared store.
                'lock' => true,
                // the pages kept by PageCache have a pool of their own: clearing them leaves the sitemap, llms.txt and the limiters alone
                'cache' => ['pools' => ['itk_cms.page_cache' => ['adapter' => 'cache.app']]],
                'rate_limiter' => [
                    'itk_request_form' => [
                        'policy' => 'sliding_window',
                        'limit' => 10,
                        'interval' => '15 minutes',
                    ],
                    // «Профиль → Сменить пароль»: five tries in 15 minutes for each person
                    'itk_password_change' => [
                        'policy' => 'sliding_window',
                        'limit' => 5,
                        'interval' => '15 minutes',
                    ],
                    'itk_two_factor' => [
                        'policy' => 'fixed_window',
                        'limit' => 5,
                        'interval' => '15 minutes',
                    ],
                    // «Забыли пароль?»: ten asks an hour from one visitor, three letters an hour to one address, ten wrong links in 15 minutes from one visitor
                    'itk_password_reset_ip' => [
                        'policy' => 'sliding_window',
                        'limit' => 10,
                        'interval' => '1 hour',
                    ],
                    'itk_password_reset_email' => [
                        'policy' => 'sliding_window',
                        'limit' => 3,
                        'interval' => '1 hour',
                    ],
                    'itk_password_reset_guess' => [
                        'policy' => 'sliding_window',
                        'limit' => 10,
                        'interval' => '15 minutes',
                    ],
                    // «Настройки → Почта → Отправить тестовое письмо»: ten letters an hour for each person
                    'itk_mail_test' => [
                        'policy' => 'sliding_window',
                        'limit' => 10,
                        'interval' => '1 hour',
                    ],
                    // MCP (AI assistants): 120 calls a minute for each key, twenty wrong keys in 15 minutes from one address; the site can override both under the same names
                    'itk_mcp_token' => [
                        'policy' => 'sliding_window',
                        'limit' => 120,
                        'interval' => '1 minute',
                    ],
                    'itk_mcp_failed_auth' => [
                        'policy' => 'sliding_window',
                        'limit' => 20,
                        'interval' => '15 minutes',
                    ],
                    // «Профиль → Мои токены»: ten new keys an hour for each person
                    'itk_mcp_token_create' => [
                        'policy' => 'sliding_window',
                        'limit' => 10,
                        'interval' => '1 hour',
                    ],
                ],
                'messenger' => [
                    'routing' => [
                        'Itk\\Cms\\Request\\SendRequestNotification' => 'async',
                        'Itk\\Cms\\Media\\GenerateImageVariants' => 'async',
                        'Itk\\Cms\\Mail\\SendMail' => 'async',
                        'Itk\\Cms\\Security\\RequestPasswordLink' => 'async',
                        'Itk\\Cms\\Security\\DeliverPasswordLink' => 'async',
                        'Itk\\Cms\\IndexNow\\Model\\PingIndexNow' => 'async',
                        'Itk\\Cms\\CriticalCss\\Model\\GenerateCriticalCss' => 'critical_css',
                        'Itk\\Cms\\KnowledgeBase\\Model\\SyncKnowledge' => 'knowledge_base',
                    ],
                ] + $this->defaultTransports($builder),
            ]);
        }
    }

    /**
     * The queue the routing above leads to. A clean project has no transports, and a router that names a missing `async` stops the container, so the bundle gives
     * the site `async`, `critical_css`, `knowledge_base` and a failure transport on its own database (doctrine://default, the table is made on first use; itk:install sets it up at once).
     * What the site configured wins: a transport it named `async`, and a failure_transport it chose (or switched off with null), are left alone.
     *
     * @return array<string, mixed>
     */
    private function defaultTransports(ContainerBuilder $builder): array
    {
        $transports = [];
        $failureChosen = false;
        foreach ($builder->getExtensionConfig('framework') as $config) {
            $messenger = $config['messenger'] ?? null;
            if (!\is_array($messenger)) {
                continue;
            }
            foreach (\array_keys((array) ($messenger['transports'] ?? [])) as $name) {
                $transports[(string) $name] = true;
            }
            $failureChosen = $failureChosen || \array_key_exists('failure_transport', $messenger);
        }

        $out = [];
        if (!isset($transports['async'])) {
            $out['transports']['async'] = [
                'dsn' => 'doctrine://default',
                'retry_strategy' => ['service' => RequestRetryStrategy::class],
            ];
        }
        if (!isset($transports['critical_css'])) {
            $out['transports']['critical_css'] = 'doctrine://default?queue_name=critical_css';
        }
        if (!isset($transports['knowledge_base'])) {
            $out['transports']['knowledge_base'] = [
                'dsn' => 'doctrine://default?queue_name=knowledge_base',
                'retry_strategy' => ['max_retries' => 5, 'delay' => 10000, 'multiplier' => 3],
            ];
        }
        if (!$failureChosen) {
            $out['failure_transport'] = 'failed';
            if (!isset($transports['failed'])) {
                $out['transports']['failed'] = 'doctrine://default?queue_name=failed';
            }
        }

        return $out;
    }
}

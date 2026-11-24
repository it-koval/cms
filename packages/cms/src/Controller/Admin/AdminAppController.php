<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\AdminBuild;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Admin\BundleAssets;
use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\AdminCsrf;
use Itk\Cms\Security\CurrentUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Twig\Environment;

/**
 * /admin (the SPA shell), /admin/login (form_login of the site's firewall) and /admin/logout; «/admin» is itk_cms.admin_path (AdminPath), the addresses given to the client follow it.
 * The shell loads the built admin from /bundles/itkcms/admin/ (names from the Vite manifest) and hands it window.ITK_ADMIN:
 *   { adminBase, apiBase, csrf, user, site: { name, domain }, mainLocale, siteTexts: { item, section }, locale, locales: [{ code, name }], previewBase, loginUrl, logoutUrl, assetsBase, images: { uploadUrl, variantDir, widths }, theme: { brand, accent, radius, logo, custom, vars, name } }
 * `locale` is the language of the interface of this person (AdminLocale), `locales` what he may choose from, each named in itself; `mainLocale` is the language of the content.
 * `theme` is the look of the admin (Admin\Theme\AdminTheme): its CSS variables are also inlined in a <style> after the bundle's styles, so the first paint already has the colours.
 * `images` is the scheme of the resized WebP copies (see Media\ImageVariants): the admin shows pictures through them (admin/src/utils/media.js).
 * Plugins: the shell defines window.ITK = { plugins: [], registerPlugin(p) } BEFORE the bundle (the admin reads `plugins` when it starts and replaces
 * registerPlugin); styles of extensions (Itk\Cms\Extension\Registry::adminAssets) come after the bundle's, their scripts wait in an inert <template> until the admin has its
 * language and its app code and starts them itself (admin/src/start.js): a plugin reads ITK.vue on its first line, and the admin loads asynchronously.
 *
 * @internal
 */
#[AsController]
final class AdminAppController
{
    public const ASSETS_URL = '/bundles/itkcms/admin/';
    private const LOGIN_TOKEN_ID = 'authenticate'; // the id form_login checks with enable_csrf: true
    private const APP_CHUNK = 'start'; // the chunk the entry imports once the language is loaded (admin/src/main.js): its name in the Vite manifest
    private const LANGUAGE_CHUNK = '~^i18n-(.+)$~'; // the chunk of a language (manualChunks in admin/vite.config.js): the name holds it, the key in the manifest only while the language is one file

    public function __construct(
        private readonly Environment $twig,
        private readonly Access $access,
        private readonly CurrentUser $current,
        private readonly AdminCsrf $csrf,
        private readonly SettingsService $settings,
        private readonly AuthenticationUtils $auth,
        private readonly ImageVariants $images,
        private readonly UrlGeneratorInterface $urls,
        private readonly Registry $extensions,
        private readonly AdminTheme $theme,
        private readonly AdminPath $admin,
        private readonly AdminLocale $locale,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        #[Autowire(param: 'itk_cms.upload_url')]
        private readonly string $uploadUrl,
        private readonly ?CsrfTokenManagerInterface $tokens = null,
        private readonly ?LogoutUrlGenerator $logoutUrls = null,
        private readonly ?BundleAssets $bundleAssets = null,
    ) {
    }

    #[Route('', name: 'itk_admin_app', methods: ['GET'])]
    #[Route('/', name: 'itk_admin_app_slash', methods: ['GET'])]
    public function app(Request $request): Response
    {
        $user = $this->current->present();
        if (null === $user) {
            return new RedirectResponse($this->urls->generate('itk_admin_login'));
        }
        $assets = $this->assets($this->locale->forUser($this->access->user()));
        $look = $this->theme->shell();
        $name = \trim((string) $this->settings->get('siteName', null, ''));
        $config = [
            'adminBase' => $this->admin->base(),
            'apiBase' => $this->admin->to('/api'),
            'csrf' => $this->csrf->token($request),
            'user' => $user,
            'site' => ['name' => $name, 'domain' => \trim((string) $this->settings->get('domain', null, ''))],
            'mainLocale' => $this->mainLocale,
            'siteTexts' => $this->locale->runContent(static fn (): array => ['item' => I18n::t('content.field_default_item'), 'section' => I18n::t('content.field_default_section')]),
            'locale' => $this->locale->forUser($this->access->user()),
            'locales' => $this->locale->choices(),
            'previewBase' => $this->admin->to('/preview'),
            'loginUrl' => $this->urls->generate('itk_admin_login'),
            'logoutUrl' => $this->logoutUrl(),
            'assetsBase' => $this->bundleAssets?->url('admin/') ?? self::ASSETS_URL,
            'images' => ['uploadUrl' => \rtrim($this->uploadUrl, '/'), 'variantDir' => ImageVariants::DIR, 'widths' => $this->images->widths()],
            'theme' => $look['theme'],
        ];

        $html = $this->twig->render('@ItkCms/admin/index.html.twig', [
            'config' => \json_encode($config, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT),
            'assets' => $assets,
            'extra' => $this->extraAssets(),
            'site_name' => $name,
            'theme_css' => $look['css'],
            'theme_brand' => $look['theme']['brand'],
            'theme_logo' => $look['theme']['logo'],
        ]);

        return new Response($html, null === $assets ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    #[Route('/login', name: 'itk_admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (null !== $this->access->user()) {
            return new RedirectResponse($this->urls->generate('itk_admin_app'));
        }
        $look = $this->theme->shell();
        $error = $this->auth->getLastAuthenticationError();
        $message = null;
        if (null !== $error) {
            $message = match (true) {
                $error instanceof CustomUserMessageAccountStatusException, $error instanceof CustomUserMessageAuthenticationException => $error->getMessageKey(),
                $error instanceof TooManyLoginAttemptsAuthenticationException => I18n::t('http.too_many_attempts'), // the limiter holds the sign-in, the password may be right
                default => I18n::t('http.login_failed'),
            };
        }
        $noticeKey = PasswordRecoveryController::NOTICES[(string) $request->query->get('notice', '')] ?? null;
        $html = $this->twig->render('@ItkCms/admin/login.html.twig', [
            'error' => $message,
            'last_username' => $this->auth->getLastUsername(),
            'csrf_token' => $this->tokens?->getToken(self::LOGIN_TOKEN_ID)->getValue(),
            'action' => $this->urls->generate('itk_admin_login'),
            'forgot_url' => $this->urls->generate('itk_admin_forgot_password'),
            'notice' => null === $message && null !== $noticeKey ? I18n::t($noticeKey) : null,
            'site_name' => \trim((string) $this->settings->get('siteName', null, '')),
            'theme_css' => $look['css'],
            'theme_brand' => $look['theme']['brand'],
            'theme_logo' => $look['theme']['logo'],
        ]);

        return new Response($html, null !== $message ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /**
     * Scripts and styles of extensions (Registry::adminAssets()), loaded after the admin's own bundle; only site paths and web addresses.
     *
     * @return array{js: list<string>, css: list<string>}
     */
    private function extraAssets(): array
    {
        $ok = static fn (string $url): bool => 1 === \preg_match('~^(/(?![/\\\\])|https?://)~i', $url);
        $assets = $this->extensions->adminAssetList();

        return ['js' => \array_values(\array_filter($assets['js'], $ok)), 'css' => \array_values(\array_filter($assets['css'], $ok))];
    }

    /** The logout address with the CSRF token of the firewall (when `logout.enable_csrf` is on), so a foreign page cannot sign the person out. */
    private function logoutUrl(): string
    {
        try {
            return $this->logoutUrls?->getLogoutPath() ?? $this->urls->generate('itk_admin_logout');
        } catch (\Throwable) {
            return $this->urls->generate('itk_admin_logout');
        }
    }

    /** Handled by the logout listener of the firewall. */
    #[Route('/logout', name: 'itk_admin_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Add `logout: { path: itk_admin_logout }` to the itk_admin firewall.');
    }

    /**
     * The entry files of the built admin: { js, css: [], preload: [] } as URLs, or null while it is not built.
     *
     * @return array{js: string, css: list<string>, preload: list<string>}|null
     */
    private function assets(string $locale): ?array
    {
        $dir = AdminBuild::DIR;
        foreach (AdminBuild::manifests($dir) as $manifest) {
            $assets = self::manifestAssets($manifest, $locale, fn (string $file): string => $this->assetUrl($file));
            if (null !== $assets) {
                return $assets;
            }
        }

        // no manifest (built without `manifest: true`): the hashed entry files of the build folder
        foreach (['bundle', 'assets'] as $sub) {
            $js = \glob($dir . '/' . $sub . '/index*.js') ?: [];
            if ([] !== $js) {
                $css = \array_map(fn (string $f): string => $this->assetUrl($sub . '/' . \basename($f)), \glob($dir . '/' . $sub . '/*.css') ?: []);

                return ['js' => $this->assetUrl($sub . '/' . \basename($js[0])), 'css' => $css, 'preload' => []];
            }
        }

        return null;
    }

    private function assetUrl(string $file): string
    {
        return $this->bundleAssets?->url('admin/' . $file) ?? self::ASSETS_URL . $file;
    }

    /**
     * The entry files of one Vite manifest, or null when it names no entry. `$locale` is the language of the person: its catalogue and the fallback one are what the admin asks for first.
     *
     * @param array<mixed> $manifest
     * @param (callable(string): string)|null $assetUrl
     *
     * @return array{js: string, css: list<string>, preload: list<string>}|null
     */
    public static function manifestAssets(array $manifest, string $locale, ?callable $assetUrl = null): ?array
    {
        $assetUrl ??= static fn (string $file): string => self::ASSETS_URL . $file;
        $entry = $manifest['index.html'] ?? null;
        if (!\is_array($entry)) {
            foreach ($manifest as $candidate) {
                if (\is_array($candidate) && !empty($candidate['isEntry'])) {
                    $entry = $candidate;
                    break;
                }
            }
        }
        if (!\is_array($entry) || !isset($entry['file'])) {
            return null;
        }
        $css = [];
        $preload = [];
        $seen = [];
        $walk = static function (array $chunk) use (&$walk, $manifest, $assetUrl, &$css, &$preload, &$seen): void {
            foreach ((array) ($chunk['css'] ?? []) as $c) {
                $css[] = $assetUrl((string) $c);
            }
            foreach ((array) ($chunk['imports'] ?? []) as $key) {
                if (isset($seen[$key]) || !isset($manifest[$key]['file'])) {
                    continue;
                }
                $seen[$key] = true;
                $preload[] = $assetUrl((string) $manifest[$key]['file']);
                $walk($manifest[$key]);
            }
        };
        foreach ($manifest as $chunk) {
            if (!\is_array($chunk) || !isset($chunk['file'])) {
                continue;
            }
            // the code of the app: its styles keep their place in the cascade ahead of the global ones of the entry, and come from here, so the theme after them still wins
            if (self::APP_CHUNK === ($chunk['name'] ?? null)) {
                $preload[] = $assetUrl((string) $chunk['file']);
                $walk($chunk);
            }
            // the catalogues the admin asks for first: its language and the fallback one
            if (1 === \preg_match(self::LANGUAGE_CHUNK, (string) ($chunk['name'] ?? ''), $language) && \in_array($language[1], [$locale, I18n::FALLBACK], true)) {
                $preload[] = $assetUrl((string) $chunk['file']);
            }
        }
        $walk($entry);
        $js = $assetUrl((string) $entry['file']);

        return ['js' => $js, 'css' => \array_values(\array_unique($css)), 'preload' => \array_values(\array_unique(\array_diff($preload, [$js])))];
    }
}

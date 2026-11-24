<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\MenuArea;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Field\Text;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\SchemaOrg\GraphBuilder;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\LoaderInterface;

/** Public rendering uses the version's content language, including inside an English admin preview. */
final class PublicLocaleRenderTest extends KernelTestCase
{
    use TemporarySettings;

    private string $prefix;
    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $items = [];
    /** @var list<string> */
    private array $users = [];
    private LoaderInterface $loader;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: [
            'main_locale' => 'ru',
            'locales' => [
                'ru' => ['name' => 'Русский', 'html_lang' => 'ru-RU', 'og_locale' => 'ru_RU'],
                'kk' => ['name' => 'Қазақша', 'og_locale' => 'kk_KZ'],
                'tr' => ['name' => 'Türkçe'],
                'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'],
                'en' => ['name' => 'English'],
            ], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->prefix = 'test_locale_' . \bin2hex(\random_bytes(5));
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $registry->add(new class extends TemplateResource {
            public function key(): string
            {
                return 'test_public_locale';
            }

            public function title(): string
            {
                return 'Public locale probe';
            }

            public function fields(): iterable
            {
                return [];
            }

            public function listing(): ?array
            {
                return ['of' => ['default']];
            }
        });
        $registry->add(new class extends CollectionResource {
            public function key(): string
            {
                return 'test_public_locale_items';
            }

            public function title(): string
            {
                return 'Public locale items';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title');
                yield \Itk\Cms\Field\Repeater::make('FAQ', 'faq')->fields(
                    Text::make('Question', 'title'),
                    \Itk\Cms\Field\Repeater::make('Answers', 'answers')->fields(Text::make('Answer', 'text')),
                );
            }
        });
        $registry->add(new class($this->prefix) extends SettingsResource {
            public function __construct(private readonly string $prefix)
            {
            }

            public function key(): string
            {
                return 'test_public_locale_settings';
            }

            public function title(): string
            {
                return 'Public locale settings';
            }

            public function fields(): iterable
            {
                yield Text::make('Translated', $this->prefix . '_text')->translatable();
                yield Text::make('Missing translation', $this->prefix . '_missing')->translatable();
                yield Text::make('Shared', $this->prefix . '_shared');
                yield Text::make('Default', $this->prefix . '_initial')->translatable()->initial('Schema default');
                yield \Itk\Cms\Field\Repeater::make('FAQ', $this->prefix . '_faq')->translatable()->fields(
                    Text::make('Question', 'title'),
                    \Itk\Cms\Field\Repeater::make('Answers', 'answers')->fields(Text::make('Answer', 'text')),
                );
            }
        });
        $twig = self::getContainer()->get(Environment::class);
        $this->loader = $twig->getLoader();
        $twig->setLoader(new ChainLoader([new ArrayLoader(['pages/test_public_locale.html.twig' => <<<'TWIG'
{% extends '@ItkCms/layout.html.twig' %}
{% block content %}
{% do itk_cache(false) %}
<script id="locale-probe" type="application/json">{{ {
    page: page,
    requestLocale: app.request ? app.request.locale : null,
    translated: 'public_locale_probe'|trans,
    site: {
        locale: site.locale,
        text: site[page.fields.probe ~ '_text'],
        missing: site[page.fields.probe ~ '_missing'],
        shared: site[page.fields.probe ~ '_shared'],
        initial: site[page.fields.probe ~ '_initial']
    },
    menu: menu(page.fields.probe),
    settings: {
        text: settings(page.fields.probe ~ '_text'),
        missing: settings(page.fields.probe ~ '_missing', 'Caller default'),
        shared: settings(page.fields.probe ~ '_shared'),
        initial: settings(page.fields.probe ~ '_initial'),
        unknown: settings('unknown_' ~ page.fields.probe, 'Unknown default')
    },
    pages: itk_pages({parent: page.id}),
    listing: listing(page),
    collection: itk_collection('test_public_locale_items'),
    filteredCollection: itk_collection('test_public_locale_items', {where: {title: 'KK record'}}),
    pageUrl: page_url(page.fields.target),
    missingPageUrl: page_url(page.fields.missingTarget),
    literalUrl: page_url({kind: 'url', url: '/literal?keep=1#fragment'})
}|json_encode|raw }}</script>
{% endblock %}
TWIG]), $this->loader]));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(Environment::class)->setLoader($this->loader);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach (\array_reverse($this->pages) as $id) {
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id = :id')->setParameter('id', $id)->execute();
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.id IN (:ids)')->setParameter('ids', $this->items ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id IN (:ids)')->setParameter('ids', $this->users ?: ['-'])->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\MenuArea m WHERE m.key = :key')->setParameter('key', $this->prefix)->execute();
        $keys = \array_map(fn (string $suffix): string => $this->prefix . $suffix, ['_text', '_missing', '_shared', '_initial', '_faq']);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key IN (:keys)')->setParameter('keys', $keys)->execute();
        self::getContainer()->get(SettingsService::class)->reset();
        $this->restoreSettings();
        parent::tearDown();
    }

    #[Test]
    public function liveRenderingExposesMetadataAndOnlyLiveConfiguredTranslationsAndRestoresLocale(): void
    {
        $page = $this->fixture();
        $switcher = self::getContainer()->get(LocaleSwitcher::class);
        $switcher->setLocale('en');
        $request = Request::create('/admin');
        $request->setLocale('en');
        $requests = self::getContainer()->get(RequestStack::class);
        $requests->push($request);
        try {
            foreach ([['ru', 'RU translation'], ['kk', 'KK translation'], ['ru', 'RU translation']] as [$locale, $message]) {
                $html = $this->live($page->translation($locale));
                $probe = $this->probe($html);
                self::assertSame($locale, $probe['page']['locale']);
                self::assertSame('ru' === $locale ? 'ru-RU' : 'kk', $probe['page']['html_lang']);
                self::assertSame($locale . ('ru' === $locale ? '_RU' : '_KZ'), $probe['page']['og_locale']);
                self::assertStringContainsString('<html lang="' . $probe['page']['html_lang'] . '">', $html);
                self::assertSame($this->translations($page), $probe['page']['translations']);
                self::assertSame($message, $probe['translated']);
                self::assertSame($locale, $probe['requestLocale']);
                self::assertSame([
                    'locale' => $locale,
                    'text' => 'ru' === $locale ? 'RU setting' : 'KK setting',
                    'missing' => 'ru' === $locale ? 'RU only setting' : '',
                    'shared' => 'Shared setting',
                    'initial' => 'Schema default',
                ], $probe['site']);
                self::assertSame('Shared setting', $probe['settings']['shared']);
                self::assertSame('Schema default', $probe['settings']['initial']);
                self::assertSame('en', $switcher->getLocale());
                self::assertSame('en', $request->getLocale());
            }
        } finally {
            $requests->pop();
        }
    }

    #[Test]
    public function draftPreviewUsesContentLanguageInsideEnglishAdminAndListsLiveVersions(): void
    {
        $page = $this->fixture();
        $page->translation('kk')->draft['title'] = 'KK draft preview';
        $page->translation('kk')->draft['url'] = '/kk/' . $this->prefix . '-draft';
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $request = Request::create('/admin/preview/' . $page->id);
        $request->setLocale('en');
        $requests = self::getContainer()->get(RequestStack::class);
        $requests->push($request);
        try {
            self::getContainer()->get(AdminLocale::class)->run('en', function () use ($page, $request): void {
                $response = self::getContainer()->get(PageRenderer::class)->renderPreview($page->id, 'kk');
                $probe = $this->probe((string) $response->getContent());
                self::assertSame('KK draft preview', $probe['page']['title']);
                self::assertSame('/kk/' . $this->prefix . '-draft', $probe['page']['url']);
                self::assertSame('kk', $probe['page']['html_lang']);
                self::assertSame('KK translation', $probe['translated']);
                self::assertSame('kk', $probe['requestLocale']);
                self::assertSame($this->translations($page), $probe['page']['translations']);
                self::assertSame('en', self::getContainer()->get(LocaleSwitcher::class)->getLocale());
                self::assertSame('en', $request->getLocale());
                self::assertSame('EN translation', self::getContainer()->get('translator')->trans('public_locale_probe'));
                self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
            });
        } finally {
            $requests->pop();
        }
    }

    #[Test]
    public function previewControllerKeepsEnglishOverlayAroundKazakhDraft(): void
    {
        $page = $this->fixture();
        $page->translation('kk')->draft['title'] = 'Kazakh draft via HTTP';
        $user = self::getContainer()->get(UserManager::class)->create($this->prefix . '@example.com', 'Preview reader', 'admin', 'password-for-test');
        $user->locale = 'en';
        $this->users[] = $user->id;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->loginUser($user, 'itk_admin');
        $client->request('GET', '/admin/preview/' . $page->id . '?locale=kk');
        $response = $client->getResponse();
        $html = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString('<html lang="kk">', $html);
        $probe = $this->probe($html);
        self::assertSame('Kazakh draft via HTTP', $probe['page']['title']);
        self::assertSame('KK translation', $probe['translated']);
        self::assertSame('kk', $probe['requestLocale']);
        self::assertSame($this->translations($page), $probe['page']['translations']);
        self::assertSame(1, \preg_match('~window\.ITK_PREVIEW=(.*?);</script>~s', $html, $match));
        $overlay = \json_decode($match[1], true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($overlay['readonly']);
        self::assertSame('Edit', $overlay['texts']['edit']);
    }

    #[Test]
    public function previewMarksCollectionAndSettingsFieldsInTheContentLanguage(): void
    {
        $page = $this->fixture();
        self::getContainer()->get(\Itk\Cms\Schema\Schema::class)->template('test_public_locale')->listing(collection: 'test_public_locale_items');
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([new ArrayLoader(['pages/test_public_locale.html.twig' => <<<'TWIG'
<html><head></head><body>
{{ itk_field(site, page.fields.probe ~ '_text') }}
<div{{ itk_field_attrs(settings(), page.fields.probe ~ '_shared') }}>{{ settings(page.fields.probe ~ '_shared') }}</div>
{% for item in itk_collection('test_public_locale_items') %}{{ itk_field(item, 'title') }}{% endfor %}
{% for item in listing(page) %}<b{{ itk_field_attrs(item, 'title') }}>{{ item.title }}</b>{% endfor %}
</body></html>
TWIG]), $this->loader]));
        foreach (['ru', 'kk'] as $locale) {
            $html = (string) self::getContainer()->get(PageRenderer::class)->renderPreview($page->id, $locale)->getContent();
            self::assertStringContainsString('data-f="' . $this->prefix . '_text" data-sec="@settings"', $html);
            self::assertStringContainsString('data-f="' . $this->prefix . '_shared" data-sec="@settings"', $html);
            self::assertStringContainsString('data-f="title" data-sec="@collection/test_public_locale_items/', $html);
            self::assertSame('ru' === $locale ? 4 : 2, \substr_count($html, 'data-sec="@collection/test_public_locale_items/'));
            self::assertStringContainsString('ru' === $locale ? 'RU record' : 'KK record', $html);
            self::assertStringContainsString('ru' === $locale ? 'RU setting' : 'KK setting', $html);
            $live = $this->live($page->translation($locale));
            self::assertStringNotContainsString('data-f=', $live);
            self::assertStringContainsString('ru' === $locale ? 'RU record' : 'KK record', $live);
            self::assertStringContainsString('ru' === $locale ? 'RU setting' : 'KK setting', $live);
        }
    }

    #[Test]
    public function repeaterMarkersCarryPublishedRowIdsForCollectionAndSettingsSubjects(): void
    {
        $page = $this->fixture();
        $a = ['_id' => 'row-A', 'title' => 'A', 'answers' => [['_id' => 'answer-X', 'text' => 'X']]];
        $b = ['_id' => 'row-B', 'title' => 'B', 'answers' => []];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->items as $id) {
            $item = $em->find(CollectionItem::class, $id);
            foreach (['ru', 'kk'] as $locale) {
                $item->translations[$locale]['draft']['faq'] = [$b, $a];
                if (null !== $item->translations[$locale]['published']) {
                    $item->translations[$locale]['published']['faq'] = [$a, $b];
                }
            }
        }
        $em->flush();
        foreach (['ru', 'kk'] as $locale) {
            self::getContainer()->get(SettingsService::class)->set($this->prefix . '_faq', [$a, $b], $locale);
        }
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([new ArrayLoader(['pages/test_public_locale.html.twig' => <<<'TWIG'
<html><head></head><body>
{% for item in itk_collection('test_public_locale_items') %}
{{ itk_field(item, 'faq.0.title') }}<div{{ itk_field_attrs(item, 'faq.0.answers.0.text') }}>Answer</div>
{% endfor %}
{{ itk_field(site, page.fields.probe ~ '_faq.0.title') }}
<div{{ itk_field_attrs(settings(), page.fields.probe ~ '_faq.0.answers.0.text') }}>Setting</div>
</body></html>
TWIG]), $this->loader]));
        foreach (['ru', 'kk'] as $locale) {
            $html = (string) self::getContainer()->get(PageRenderer::class)->renderPreview($page->id, $locale)->getContent();
            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            foreach ((new \DOMXPath($document))->query('//*[@data-f]') as $marker) {
                $nested = \str_contains($marker->getAttribute('data-f'), 'answers');
                self::assertSame($nested ? ['row-A', 'answer-X'] : ['row-A'], \json_decode($marker->getAttribute('data-row-ids'), true), $marker->getAttribute('data-f'));
            }
            self::assertStringNotContainsString('data-row-ids=', $this->live($page->translation($locale)));
        }
    }

    #[Test]
    public function settingsOwnerKeysDoNotChangePreviewMarkersOrLiveFieldValues(): void
    {
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $registry->add(new class extends SettingsResource {
            public function key(): string
            {
                return 'test_settings_owner_keys';
            }

            public function title(): string
            {
                return 'Settings owner keys';
            }

            public function fields(): iterable
            {
                foreach (['type', 'template', 'fields'] as $key) {
                    yield Text::make($key, $key);
                }
                yield \Itk\Cms\Field\Repeater::make('id', 'id')->fields(Text::make('Title', 'title'));
            }
        });
        $page = $this->fixture();
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([new ArrayLoader(['pages/test_public_locale.html.twig' => <<<'TWIG'
<html><head></head><body>
<p>{{ itk_field(site, page.fields.probe ~ '_text') }}</p>
<p>{{ itk_field(settings(), page.fields.probe ~ '_text') }}</p>
<div{{ itk_field_attrs(site, page.fields.probe ~ '_text') }}>Site</div>
<div{{ itk_field_attrs(settings(), page.fields.probe ~ '_text') }}>Settings</div>
</body></html>
TWIG]), $this->loader]));
        foreach (['type' => 'test-settings-owner', 'id' => [['_id' => 'owner', 'title' => 'Owner']], 'template' => 'test-settings-owner', 'fields' => 'test-settings-owner'] as $key => $value) {
            $this->setting($key, $value);
            foreach (['ru', 'kk'] as $locale) {
                $text = 'ru' === $locale ? 'RU setting' : 'KK setting';
                $preview = (string) self::getContainer()->get(PageRenderer::class)->renderPreview($page->id, $locale)->getContent();
                self::assertSame(4, \substr_count($preview, 'data-sec="@settings"'), $key);
                self::assertSame(2, \substr_count($preview, $text), $key);
                $live = $this->live($page->translation($locale));
                self::assertStringNotContainsString('data-sec=', $live);
                self::assertSame(2, \substr_count($live, $text), $key);
            }
            self::getContainer()->get(SettingsService::class)->remove($key);
        }
    }

    #[Test]
    public function existingTwigHelpersReadKazakhLiveContentWithoutRussianFallback(): void
    {
        $page = $this->fixture();
        $probe = $this->probe($this->live($page->translation('kk')));
        self::assertSame(['KK page', 'Literal URL'], \array_column($probe['menu'], 'label'));
        self::assertSame(['/kk/' . $this->prefix . '-child', '/literal?keep=1#fragment'], \array_column($probe['menu'], 'url'));
        self::assertSame([
            'text' => 'KK setting', 'missing' => 'Caller default', 'shared' => 'Shared setting',
            'initial' => 'Schema default', 'unknown' => 'Unknown default',
        ], $probe['settings']);
        foreach (['pages', 'listing'] as $key) {
            self::assertSame(['KK child'], \array_column($probe[$key], 'title'));
            self::assertSame(['/kk/' . $this->prefix . '-child'], \array_column($probe[$key], 'url'));
        }
        foreach (['collection', 'filteredCollection'] as $key) {
            self::assertSame(['KK record'], \array_column($probe[$key], 'title'));
        }
        self::assertSame('/kk/' . $this->prefix . '-child', $probe['pageUrl']);
        self::assertSame('', $probe['missingPageUrl']);
        self::assertSame('/literal?keep=1#fragment', $probe['literalUrl']);
    }

    #[Test]
    public function graphUsesHtmlLanguageAndStoredFullPublishedAddress(): void
    {
        $this->setting('domain', 'example.com');
        $this->setting('siteName', 'Locale site');
        $page = $this->newPage('blog_article');
        $t = $this->version($page, 'zh', '/zh/' . $this->prefix . '-article', 'Chinese article');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $nodes = \array_column(self::getContainer()->get(GraphBuilder::class)->build($t), null, '@type');
        self::assertSame('zh-CN', $nodes['WebSite']['inLanguage']);
        self::assertSame('zh-CN', $nodes['BlogPosting']['inLanguage']);
        self::assertSame('https://example.com/zh/' . $this->prefix . '-article', $nodes['BlogPosting']['mainEntityOfPage']);
        self::assertSame('https://example.com/zh/' . $this->prefix . '-article#article', $nodes['BlogPosting']['@id']);
    }

    private function fixture(): Page
    {
        $page = $this->newPage('test_public_locale');
        $child = $this->newPage(parent: $page);
        $missing = $this->newPage(parent: $page);
        $fields = ['probe' => $this->prefix, 'target' => $child->id, 'missingTarget' => $missing->id];
        // Deliberately insert KK before RU: the public selector follows the configured order.
        $this->version($page, 'kk', '/kk/' . $this->prefix, 'KK page', fields: $fields);
        $this->version($page, 'ru', '/' . $this->prefix, 'RU page', fields: $fields);
        $this->version($page, 'tr', '/tr/' . $this->prefix, 'TR draft', live: false, fields: $fields);
        $this->version($page, 'de', '/de/' . $this->prefix, 'Unconfigured live version', fields: $fields);
        $this->version($child, 'ru', '/' . $this->prefix . '-child', 'RU child');
        $this->version($child, 'kk', '/kk/' . $this->prefix . '-child', 'KK child');
        $this->version($missing, 'ru', '/' . $this->prefix . '-missing', 'RU-only child');
        $this->version($missing, 'kk', '/kk/' . $this->prefix . '-missing', 'KK draft child', live: false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $menu = new MenuArea($this->prefix, 'Locale probe', 1);
        $menu->items = [
            'ru' => [['label' => 'RU menu', 'link' => ['kind' => 'url', 'url' => '/ru-menu']]],
            'kk' => [
                ['label' => 'KK page', 'link' => ['kind' => 'page', 'page' => '/kk/' . $this->prefix . '-child']],
                ['label' => 'Missing live KK', 'link' => ['kind' => 'page', 'page' => '/kk/' . $this->prefix . '-missing']],
                ['label' => 'Literal URL', 'link' => ['kind' => 'url', 'url' => '/literal?keep=1#fragment']],
            ],
        ];
        $em->persist($menu);
        foreach ([true, false] as $kazakh) {
            $item = new CollectionItem('test_public_locale_items');
            $item->translations = ['ru' => ['draft' => ['title' => 'RU record'], 'published' => ['title' => 'RU record'], 'status' => 'published']];
            $item->translations['kk'] = ['draft' => ['title' => 'KK record'], 'published' => $kazakh ? ['title' => 'KK record'] : null, 'status' => $kazakh ? 'published' : 'draft'];
            $this->items[] = $item->id;
            $em->persist($item);
        }
        $em->flush();
        self::getContainer()->get(LiveUrlIndex::class)->reset();
        $settings = self::getContainer()->get(SettingsService::class);
        $settings->set($this->prefix . '_text', 'RU setting', 'ru');
        $settings->set($this->prefix . '_text', 'KK setting', 'kk');
        $settings->set($this->prefix . '_missing', 'RU only setting', 'ru');
        $settings->set($this->prefix . '_shared', 'Shared setting');

        return $page;
    }

    private function newPage(string $template = 'default', ?Page $parent = null): Page
    {
        $page = new Page($template, $parent);
        $this->pages[] = $page->id;
        self::getContainer()->get(EntityManagerInterface::class)->persist($page);

        return $page;
    }

    private function version(Page $page, string $locale, string $url, string $title, bool $live = true, array $fields = []): PageTranslation
    {
        $t = new PageTranslation($page, $locale);
        $t->draft = ['title' => $title, 'url' => $url, 'seo' => ['title' => $title, 'description' => ''], 'fields' => $fields, 'sections' => []];
        if ($live) {
            $t->published = $t->draft;
            $t->publishedUrl = $url;
            $t->status = PageTranslation::PUBLISHED;
            $t->publishedAt = new \DateTimeImmutable();
        }
        $page->translations->set($locale, $t);
        self::getContainer()->get(EntityManagerInterface::class)->persist($t);

        return $t;
    }

    /** @return list<array{code: string, name: string, url: string}> */
    private function translations(Page $page): array
    {
        return [
            ['code' => 'ru', 'name' => 'Русский', 'url' => $page->translation('ru')->published['url']],
            ['code' => 'kk', 'name' => 'Қазақша', 'url' => $page->translation('kk')->published['url']],
        ]; // i18n-ignore: configured language names
    }

    private function live(PageTranslation $t): string
    {
        // Invoke the renderer directly: these assertions never depend on an existing PageCache entry.
        return (string) self::getContainer()->get(PageRenderer::class)->renderLive($t)->getContent();
    }

    /** @return array<string, mixed> */
    private function probe(string $html): array
    {
        self::assertSame(1, \preg_match('~<script id="locale-probe" type="application/json">(.*?)</script>~s', $html, $match), $html);

        return \json_decode($match[1], true, flags: \JSON_THROW_ON_ERROR);
    }
}

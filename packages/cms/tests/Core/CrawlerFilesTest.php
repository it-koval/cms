<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Controller\Site\CrawlerFilesController;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** GET /robots.txt и /llms.txt: текст по умолчанию для любого сайта, свой текст из «Настройки → SEO», файл в public/ важнее; API seo-files. */
final class CrawlerFilesTest extends WebTestCase
{
    private const DOMAIN = 'test-crawler.example.com';

    private KernelBrowser $client;
    private Connection $connection;
    private SettingsService $settings;
    private CrawlerFiles $files;
    private string $csrf = '';
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $c = self::getContainer();
        $connection = $c->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        $this->connection = $connection;
        $this->settings = $c->get(SettingsService::class);
        $this->files = $c->get(CrawlerFiles::class);
        $this->settings->set('domain', self::DOMAIN);
        $this->settings->set('siteName', 'Тестовый сайт');
        $this->settings->set('robotsTxt', '');
        $this->settings->set('llmsTxt', '');
        $this->files->forget();
        $this->signIn($c->get(UserManager::class)->create('test-crawler@example.com', 'Тест файлов', 'admin', 'password-for-test'));
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->tmpFiles as $file) {
                @\unlink($file);
            }
            if (isset($this->connection)) {
                $this->connection->rollBack();
            }
            if (isset($this->files)) {
                $this->files->forget();
                self::getContainer()->get(SitemapBuilder::class)->forget();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /** The person the pages are made by (the API calls of the browser leave the token of their own). */
    private function asAdmin(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new User('test-crawler@example.com', 'Тест файлов', 'admin'), 'itk_admin', []));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $seo */
    private function live(string $title, string $url, array $seo = []): string
    {
        $this->asAdmin();
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => $title, 'url' => $url]);
        if ([] !== $seo) {
            $pages->saveDraft($page['id'], null, ['seo' => $seo] + $pages->getDraft($page['id'])['page']);
        }
        $pages->publish($page['id']);

        return $page['id'];
    }

    /**
     * The home page of the installation, published with the SEO given inside the test transaction.
     *
     * @param array<string, mixed> $seo
     */
    private function liveHome(array $seo): void
    {
        $this->asAdmin();
        $pages = self::getContainer()->get(PageService::class);
        $row = $this->connection->fetchAssociative(
            "SELECT page_id, status FROM itk_page_translation WHERE locale = ? AND draft ->> 'url' = '/'",
            [self::getContainer()->getParameter('itk_cms.main_locale')],
        );
        self::assertIsArray($row, 'the installation has a home page');
        if ('draft' !== $row['status']) {
            self::markTestSkipped('the home page of this database is live: the test does not touch it');
        }
        $id = (string) $row['page_id'];
        $draft = $pages->getDraft($id)['page'];
        $pages->saveDraft($id, null, ['seo' => $seo] + $draft);
        $pages->publish($id);
    }

    private function get(string $path, string $method = 'GET'): string
    {
        $this->client->request($method, $path);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    #[Test]
    public function robotsTxtHasAStandardTextWithTheSitemapLine(): void
    {
        $body = $this->get('/robots.txt');

        self::assertSame("User-agent: *\nDisallow: /admin/\nDisallow: /request/\nDisallow: /mcp\n\nSitemap: https://" . self::DOMAIN . "/sitemap.xml\n", $body);
        $headers = $this->client->getResponse()->headers;
        self::assertSame('text/plain; charset=UTF-8', $headers->get('Content-Type'));
        self::assertStringContainsString('public', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=600', (string) $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
    }

    #[Test]
    public function withoutTheSiteAddressThereIsNoSitemapLine(): void
    {
        $this->settings->set('domain', '');

        self::assertSame("User-agent: *\nDisallow: /admin/\nDisallow: /request/\nDisallow: /mcp\n", $this->get('/robots.txt'));
    }

    #[Test]
    public function theAddressesOfTheStandardTextAreTheRealRoutesOfTheBundle(): void
    {
        $router = self::getContainer()->get('router');

        self::assertSame('/request/callback', $router->generate('itk_request_submit', ['form' => 'callback']));
        self::assertSame('/mcp', $router->generate('itk_mcp'));
        self::assertSame('/robots.txt', $router->generate('itk_robots'));
        self::assertSame('/llms.txt', $router->generate('itk_llms'));
    }

    #[Test]
    public function headAnswersLikeGetWithoutABody(): void
    {
        foreach (['/robots.txt', '/llms.txt'] as $path) {
            $this->client->request('HEAD', $path);

            self::assertResponseIsSuccessful();
            self::assertSame('', (string) $this->client->getResponse()->getContent(), $path);
            self::assertSame('text/plain; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'), $path);
        }
    }

    #[Test]
    public function aSavedTextIsSentInsteadOfTheStandardOne(): void
    {
        $state = $this->api('PUT', 'seo-files', ['robots' => "User-agent: *\r\nDisallow: /secret/  \r\n"]);

        self::assertSame("User-agent: *\nDisallow: /secret/", $state['robots']['text']);
        self::assertTrue($state['robots']['custom']);
        self::assertFalse($state['llms']['custom'], 'a key that is not sent stays as it is');
        self::assertStringContainsString('Disallow: /admin/', $state['robots']['default']);
        self::assertSame("User-agent: *\nDisallow: /secret/\n", $this->get('/robots.txt'));
        self::assertSame($state, $this->api('GET', 'seo-files'));
    }

    #[Test]
    public function emptyNullOrTheStandardTextBringBackTheStandardOne(): void
    {
        foreach ([null, '', "  \n ", "User-agent: *\r\nDisallow: /admin/\r\nDisallow: /request/\r\nDisallow: /mcp\r\n\r\nSitemap: https://" . self::DOMAIN . "/sitemap.xml\r\n"] as $reset) {
            $this->api('PUT', 'seo-files', ['robots' => 'Disallow: /x']);
            self::assertTrue($this->api('GET', 'seo-files')['robots']['custom']);

            $state = $this->api('PUT', 'seo-files', ['robots' => $reset]);

            self::assertFalse($state['robots']['custom'], \json_encode($reset));
            self::assertSame($state['robots']['default'], $state['robots']['text']);
            self::assertSame('', $this->settings->get('robotsTxt'), 'nothing of the standard text is kept: it follows the site');
        }
    }

    #[Test]
    public function aFileInPublicWinsAndTheApiSaysSo(): void
    {
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/public/robots.txt';
        self::assertFileDoesNotExist($file);
        $this->tmpFiles[] = $file;
        @\mkdir(\dirname($file), 0777, true); // public/ of the test app is not in git: a fresh checkout has none
        \file_put_contents($file, "User-agent: *\nDisallow: /from-file/\n");

        self::assertTrue($this->api('GET', 'seo-files')['robots']['file']);
        self::assertFalse($this->api('GET', 'seo-files')['llms']['file']);
        $this->client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertSame("User-agent: *\nDisallow: /from-file/\n", $this->client->getInternalResponse()->getContent());
        self::assertSame('text/plain; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));

        $this->api('PUT', 'seo-files', ['robots' => 'Disallow: /saved/']);
        $this->client->request('GET', '/robots.txt');
        self::assertSame("User-agent: *\nDisallow: /from-file/\n", $this->client->getInternalResponse()->getContent(), 'the saved text does not beat the file');

        \unlink($file);
        self::assertFalse($this->api('GET', 'seo-files')['robots']['file']);
        self::assertSame("Disallow: /saved/\n", $this->get('/robots.txt'));
    }

    #[Test]
    public function theControllerSendsAFileOfTheSiteWhateverTheProjectIs(): void
    {
        $dir = \sys_get_temp_dir() . '/test-crawler-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/public', 0777, true);
        \file_put_contents($dir . '/public/llms.txt', '# Own file');
        $this->tmpFiles[] = $dir . '/public/llms.txt';
        try {
            $files = new CrawlerFiles(
                $this->settings,
                self::getContainer()->get(\Itk\Cms\Content\SitemapBuilder::class),
                self::getContainer()->get(\Itk\Cms\Content\IndexablePages::class),
                self::getContainer()->get(\Itk\Cms\Admin\AdminPath::class),
                self::getContainer()->get('cache.app'),
                $dir,
            );
            $response = (new CrawlerFilesController($files))->llms();

            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertSame($dir . '/public/llms.txt', $response->getFile()->getPathname());
            self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
            self::assertNull($files->staticFile(CrawlerFiles::ROBOTS));
        } finally {
            @\unlink($dir . '/public/llms.txt');
            @\rmdir($dir . '/public');
            @\rmdir($dir);
        }
    }

    #[Test]
    public function llmsTxtListsTheLivePagesOfTheMainLanguage(): void
    {
        $this->liveHome(['title' => 'Главная [тест]', 'description' => "Описание\nсайта"]);
        $this->live('Услуги', '/test-llms-services', ['title' => 'Наши услуги', 'description' => 'Что мы делаем']);
        $this->live('Без описания', '/test-llms-bare');
        $this->live('Скобки', '/test-llms-brackets', ['title' => 'A [b] c\\d', 'description' => "Две\n\nстроки"]);
        $this->live('Адрес (со скобками)', '/test-llms-блог (1)');

        $body = $this->get('/llms.txt');

        $lines = \explode("\n", $body);
        self::assertSame('# Тестовый сайт', $lines[0]);
        self::assertSame('', $lines[1]);
        self::assertSame('> Описание сайта', $lines[2]);
        self::assertContains('## Pages', $lines);
        self::assertContains('- [Главная \[тест\]](https://' . self::DOMAIN . '/): Описание сайта', $lines);
        self::assertContains('- [Наши услуги](https://' . self::DOMAIN . '/test-llms-services): Что мы делаем', $lines);
        self::assertContains('- [Без описания](https://' . self::DOMAIN . '/test-llms-bare)', $lines);
        self::assertContains('- [A \[b\] c\\\\d](https://' . self::DOMAIN . '/test-llms-brackets): Две строки', $lines);
        self::assertContains('- [Адрес (со скобками)](https://' . self::DOMAIN . '/test-llms-%D0%B1%D0%BB%D0%BE%D0%B3%20%281%29)', $lines);
        self::assertStringEndsWith("\n", $body);
        self::assertSame('text/plain; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function aPageWithNoindexAndPagesOfOtherLanguagesAreLeftOut(): void
    {
        $this->live('Открытая', '/test-llms-open');
        $this->live('Закрытая', '/test-llms-closed', ['title' => 'Закрытая', 'description' => '']);
        $this->live('Правило', '/test-llms-rule/page');
        $this->asAdmin();
        $pages = self::getContainer()->get(PageService::class);
        $other = $pages->create(['template' => 'default', 'title' => 'Other language', 'url' => '/test-llms-en', 'locale' => 'en']);
        $pages->publish($other['id'], 'en');
        $rules = self::getContainer()->get(SeoRuleService::class);
        $rules->create(['path' => '/test-llms-closed', 'match' => 'exact', 'robots' => 'noindex,follow']);
        $rules->create(['path' => '/test-llms-rule', 'match' => 'prefix', 'robots' => 'noindex,nofollow']);

        $body = $this->get('/llms.txt');

        self::assertStringContainsString('(https://' . self::DOMAIN . '/test-llms-open)', $body);
        self::assertStringNotContainsString('test-llms-closed', $body, 'noindex of an SEO rule');
        self::assertStringNotContainsString('test-llms-rule', $body, 'noindex of a rule of a section');
        self::assertStringNotContainsString('test-llms-en', $body, 'another language');
    }

    #[Test]
    public function withoutTheSiteAddressTheLinksAreRelativeAndTheNameIsTheFallback(): void
    {
        $this->settings->set('domain', '');
        $this->settings->set('siteName', '');
        $this->live('Относительная', '/test-llms-relative');

        $body = $this->get('/llms.txt');
        self::assertStringStartsWith("# Site\n", $body);
        self::assertStringContainsString('- [Относительная](/test-llms-relative)', $body);

        $this->settings->set('domain', self::DOMAIN);
        self::assertStringStartsWith('# ' . self::DOMAIN . "\n", $this->get('/llms.txt'), 'without a name: the address of the site');
    }

    #[Test]
    public function llmsTxtIsKeptAndDroppedWhenAPageOrTheNameChanges(): void
    {
        $id = $this->live('Кэш', '/test-llms-cache');
        self::assertStringContainsString('/test-llms-cache)', $this->get('/llms.txt'));

        $this->asAdmin();
        $pages = self::getContainer()->get(PageService::class);
        $pages->unpublish($id);
        self::assertStringNotContainsString('/test-llms-cache)', $this->get('/llms.txt'), 'unpublished');

        $pages->publish($id);
        self::assertStringContainsString('/test-llms-cache)', $this->get('/llms.txt'), 'published again');

        $pages->unpublish($id);
        self::assertStringNotContainsString('/test-llms-cache)', $this->get('/llms.txt'), 'unpublished again');
        $pages->republish($id);
        self::assertStringContainsString('/test-llms-cache)', $this->get('/llms.txt'), '«Вернуть на сайт» after unpublishing');
        self::assertStringContainsString('/test-llms-cache</loc>', (string) self::getContainer()->get(SitemapBuilder::class)->xml(), 'the sitemap too');

        $rule = self::getContainer()->get(SeoRuleService::class)->create(['path' => '/test-llms-cache', 'match' => 'exact', 'robots' => 'noindex,follow']);
        self::assertStringNotContainsString('/test-llms-cache)', $this->get('/llms.txt'), 'an SEO rule was added');
        self::getContainer()->get(SeoRuleService::class)->remove($rule->id);
        self::assertStringContainsString('/test-llms-cache)', $this->get('/llms.txt'), 'the rule was removed');

        $this->settings->set('siteName', 'Новое имя');
        self::assertStringStartsWith("# Новое имя\n", $this->get('/llms.txt'), 'the name changed');
        $this->settings->set('domain', 'other-' . self::DOMAIN);
        self::assertStringContainsString('(https://other-' . self::DOMAIN . '/test-llms-cache)', $this->get('/llms.txt'), 'the address changed');
    }

    #[Test]
    public function aSavedLlmsTextIsSentAndTheStandardOneStaysAvailable(): void
    {
        $this->live('Страница', '/test-llms-saved');

        $state = $this->api('PUT', 'seo-files', ['llms' => "# Свой текст\n"]);

        self::assertSame('# Свой текст', $state['llms']['text']);
        self::assertTrue($state['llms']['custom']);
        self::assertStringContainsString('/test-llms-saved)', $state['llms']['default']);
        self::assertSame("# Свой текст\n", $this->get('/llms.txt'));

        $state = $this->api('PUT', 'seo-files', ['llms' => $state['llms']['default']]);
        self::assertFalse($state['llms']['custom'], 'the standard text is not kept as a custom one');
        self::assertStringContainsString('/test-llms-saved)', $this->get('/llms.txt'));
    }

    #[Test]
    public function aTooLongOrNotATextIsRefusedAndNothingIsSaved(): void
    {
        $limit = \str_repeat('a', 65536);
        $state = $this->api('PUT', 'seo-files', ['robots' => $limit]);
        self::assertTrue($state['robots']['custom'], '64 KB is allowed');
        $this->api('PUT', 'seo-files', ['robots' => null]);

        $error = $this->api('PUT', 'seo-files', ['robots' => $limit . 'b', 'llms' => 'fine'], 422);
        self::assertArrayHasKey('error', $error);
        self::assertArrayHasKey('robots', $error['details']);
        self::assertArrayNotHasKey('llms', $error['details']);
        self::assertStringContainsString('64', $error['details']['robots']);
        self::assertFalse($this->api('GET', 'seo-files')['llms']['custom'], 'the valid half of a refused call is not saved either');

        $bytes = $this->api('PUT', 'seo-files', ['llms' => \str_repeat('я', 32769)], 422);
        self::assertArrayHasKey('llms', $bytes['details'], 'the limit counts bytes');

        $notText = $this->api('PUT', 'seo-files', ['robots' => 123, 'llms' => ['a']], 422);
        self::assertSame(['robots', 'llms'], \array_keys($notText['details']));
    }

    #[Test]
    public function aRoleWithoutTheAreaHasNoAccess(): void
    {
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-crawler-limited@example.com', 'Узкий', 'requests', 'password-for-test'));

        $this->api('GET', 'seo-files', [], 403);
        $this->api('PUT', 'seo-files', ['robots' => 'Disallow: /'], 403);
        self::assertSame('', $this->settings->get('robotsTxt'));
    }

    #[Test]
    public function aChangeGoesIntoTheHistoryAndCanBeReturned(): void
    {
        $this->api('PUT', 'seo-files', ['robots' => "User-agent: *\nDisallow: /one/", 'llms' => '# Один']);

        $history = $this->api('GET', 'history/settings/seo-files');
        $labels = \array_column(\array_merge(...\array_column($history['items'], 'summary')), 'label');
        self::assertContains('robots.txt', $labels);
        self::assertContains('llms.txt', $labels);
        self::assertSame('SEO-файлы', $history['items'][0]['title']);

        $baseline = \array_values(\array_filter($history['items'], static fn (array $e): bool => $e['baseline']))[0];
        $this->api('POST', 'history/revision/' . $baseline['id'] . '/restore');
        $state = $this->api('GET', 'seo-files');
        self::assertFalse($state['robots']['custom']);
        self::assertFalse($state['llms']['custom']);
    }

    #[Test]
    public function theSettingsApiNeitherShowsNorTakesTheTexts(): void
    {
        $this->api('PUT', 'seo-files', ['robots' => 'Disallow: /kept/']);

        $settings = $this->api('GET', 'settings');
        self::assertArrayNotHasKey('robotsTxt', $settings);
        self::assertArrayNotHasKey('llmsTxt', $settings);

        $this->api('PATCH', 'settings', ['robotsTxt' => 'Disallow: /sneaked/', 'llmsTxt' => '# x']);
        self::assertSame('Disallow: /kept/', $this->settings->get('robotsTxt'));
        self::assertSame('', $this->settings->get('llmsTxt'));
    }
}

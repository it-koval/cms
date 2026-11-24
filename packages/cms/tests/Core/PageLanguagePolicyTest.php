<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Configured site languages govern the admin API and page addresses; an older site keeps its permissive writes. */
final class PageLanguagePolicyTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    private string $userId = '';
    /** @var list<string> */
    private array $pageIds = [];
    /** @var list<string> */
    private array $itemIds = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        $itk = ['main_locale' => 'ru'];
        if ($options['contentLocales'] ?? true) {
            // The main language moves to the front; the additional languages retain their declared order.
            $itk['locales'] = [
                'kk' => ['name' => 'Қазақша'], // i18n-ignore: display name from site configuration
                'tr' => ['name' => 'Türkçe'],
                'ru' => ['name' => 'Русский'], // i18n-ignore: display name from site configuration
                'zh' => ['name' => '中文'],
                'en' => ['name' => 'English'],
            ];
        }

        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, itk: $itk);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->userId) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $ids = [] === $this->pageIds ? ['-'] : $this->pageIds;
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id IN (:ids)')->setParameter('ids', $ids)->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', $ids)->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.id IN (:ids)')->setParameter('ids', $this->itemIds ?: ['-'])->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', $this->itemIds ?: ['-'])->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id = :id')->setParameter('id', $this->userId)->execute();
        }
        parent::tearDown();
    }

    private function start(bool $configured = true): void
    {
        $this->client = static::createClient(['contentLocales' => $configured]);
        $this->client->disableReboot();
        $user = self::getContainer()->get(UserManager::class)->create('test-language-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Test languages', 'admin', 'password-for-test');
        $this->userId = $user->id;
        $this->client->loginUser($user, 'itk_admin');
        $this->csrf = (string) $this->api('GET', 'me')['csrf'];
        self::assertNotSame('', $this->csrf);
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body, \JSON_THROW_ON_ERROR));
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $content);

        return (array) \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        $page = $this->api('POST', 'pages', ['title' => 'Test language policy', 'url' => '/test-language-' . \bin2hex(\random_bytes(6))], 201);
        $this->pageIds[] = $page['id'];

        return $page;
    }

    private function addTranslation(string $id, string $locale, string $url): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->find(Page::class, $id);
        $translation = new PageTranslation($page, $locale);
        $translation->draft = $page->translation('ru')->draft;
        $translation->draft['url'] = $url;
        $page->translations->set($locale, $translation);
        $em->persist($translation);
        $em->flush();
    }

    #[Test]
    public function theLanguageEndpointAndDashboardUseTheSameFiveSiteLanguages(): void
    {
        $this->start();
        $expected = ['main' => 'ru', 'list' => [
            ['code' => 'ru', 'name' => 'Русский'], // i18n-ignore: display name from site configuration
            ['code' => 'kk', 'name' => 'Қазақша'], // i18n-ignore: display name from site configuration
            ['code' => 'tr', 'name' => 'Türkçe'],
            ['code' => 'zh', 'name' => '中文'],
            ['code' => 'en', 'name' => 'English'],
        ]];
        self::assertSame($expected, $this->api('GET', 'languages'));
        self::assertSame($expected, $this->api('GET', 'dashboard')['languages']);
    }

    #[Test]
    public function dashboardWithoutALanguageListKeepsLegacyTranslationWorkVisible(): void
    {
        $this->start(false);
        $page = $this->page();
        $this->addTranslation($page['id'], 'en', '/test-legacy-dashboard-' . \bin2hex(\random_bytes(6)));
        $pages = self::getContainer()->get(PageService::class);
        $pages->publish($page['id'], 'en');
        $pages->saveDraft($page['id'], 'en', ['title' => 'Legacy EN changed']);
        $pages->submit($page['id'], 'en');
        $collections = self::getContainer()->get(CollectionService::class);
        $item = $collections->create('tours', ['city' => 'Legacy EN', 'dateStart' => '2026-11-25', 'dateEnd' => '2026-11-26'], 'en');
        $this->itemIds[] = $item['id'];
        $collections->submit('tours', $item['id'], 'en');
        $dashboard = $this->api('GET', 'dashboard');
        foreach ([$page['id'], $item['id']] as $id) {
            $rows = \array_values(\array_filter($dashboard['unpublished'], static fn (array $r): bool => $r['id'] === $id && 'en' === $r['locale']));
            self::assertCount(1, $rows);
            self::assertContains($id, \array_column($dashboard['awaiting'], 'id'));
        }
        self::assertContains('en', \array_column($dashboard['recent'], 'locale'));
        self::assertSame(['ru'], \array_keys($dashboard['locales']));
    }

    #[Test]
    public function anUnknownDraftLanguageIsRejectedBeforeAnyTranslationIsWritten(): void
    {
        $this->start();
        $page = $this->page();
        $error = $this->api('PUT', 'pages/' . $page['id'] . '/draft?locale=zz', ['title' => 'Unknown language'], 422);
        self::assertStringContainsString('zz', $error['error']);
        self::assertNull(self::getContainer()->get(PageService::class)->find($page['id'])->translation('zz'));
        self::assertSame($page['title'], $this->api('GET', 'pages/' . $page['id'])['title']);
    }

    #[Test]
    public function draftAddressesFollowTheContentLanguagePrefix(): void
    {
        $this->start();
        $page = $this->page();
        $this->addTranslation($page['id'], 'kk', '/kk/test-language-' . \bin2hex(\random_bytes(6)));
        $kk = $this->api('PUT', 'pages/' . $page['id'] . '/draft?locale=kk', ['url' => '/about'], 422);
        self::assertStringContainsString('/kk', $kk['error']);
        self::assertStringContainsString('Қазақша', $kk['error']); // i18n-ignore: display name from site configuration
        $ru = $this->api('PUT', 'pages/' . $page['id'] . '/draft?locale=ru', ['url' => '/kk/x'], 422);
        self::assertStringContainsString('/kk', $ru['error']);
        self::assertStringContainsString('Қазақша', $ru['error']); // i18n-ignore: display name from site configuration
        self::assertSame($page['url'], $this->api('GET', 'pages/' . $page['id'])['url']);
        $validUrl = '/kk/test-language-' . \bin2hex(\random_bytes(6));
        $saved = $this->api('PUT', 'pages/' . $page['id'] . '/draft?locale=kk', ['url' => $validUrl]);
        self::assertSame($validUrl, $saved['url']);
    }

    #[Test]
    public function anExistingPrefixlessTranslationCanBeEditedButNeedsThePrefixToPublish(): void
    {
        $this->start();
        $page = $this->page();
        $oldUrl = '/test-language-legacy-' . \bin2hex(\random_bytes(6));
        $this->addTranslation($page['id'], 'kk', $oldUrl);
        $path = 'pages/' . $page['id'];

        $saved = $this->api('PUT', $path . '/draft?locale=kk', ['title' => 'Edited legacy translation']);
        self::assertSame('Edited legacy translation', $saved['title']);
        self::assertSame($oldUrl, $saved['url']);

        $wrongUrl = '/test-language-other-' . \bin2hex(\random_bytes(6));
        $wrong = $this->api('PUT', $path . '/draft?locale=kk', ['url' => $wrongUrl], 422);
        self::assertStringContainsString('/kk', $wrong['error']);
        $draft = $this->api('GET', $path . '/draft?locale=kk')['page'];
        self::assertSame($oldUrl, $draft['url']);
        self::assertSame('Edited legacy translation', $draft['title']);
        // Publishing validates required fields before the address: prove this fixture reaches the address guard.
        self::assertSame([], self::getContainer()->get(PageService::class)->requiredErrors($draft, $draft['template']));

        $publish = $this->api('POST', $path . '/publish?locale=kk', [], 422);
        self::assertSame(I18n::t('content.url_locale_prefix', [
            'url' => $oldUrl,
            'prefix' => '/kk',
            'language' => self::getContainer()->get(ContentLocales::class)->name('kk'),
        ]), $publish['error']);
        self::assertStringContainsString('/kk', $publish['error']);
        self::assertNull($this->api('GET', $path . '/draft?locale=kk')['published']);
    }

    #[Test]
    public function aTranslationDraftCanKeepItsAddressEmptyUntilPublishing(): void
    {
        $this->start();
        $page = $this->page();
        $this->addTranslation($page['id'], 'kk', '/kk/test-language-' . \bin2hex(\random_bytes(6)));
        $path = 'pages/' . $page['id'];
        self::assertSame('', $this->api('PUT', $path . '/draft?locale=kk', ['url' => ''])['url']);
        $publish = $this->api('POST', $path . '/publish?locale=kk', [], 422);
        self::assertSame(I18n::t('content.page_publish_url_required'), $publish['error']);
    }

    #[Test]
    public function changedAddressesAndPublicationRejectMalformedPaths(): void
    {
        $this->start();
        $page = $this->page();
        $path = 'pages/' . $page['id'];
        $invalid = ['relative', '/bad?x=1', '/bad#fragment', '/bad\\path', '//host/path', '/bad' . "\n" . 'path', '/' . \str_repeat('a', 255)];
        foreach ($invalid as $url) {
            $saved = $this->api('PUT', $path . '/draft', ['url' => $url], 422);
            self::assertSame(I18n::t('content.page_url_invalid'), $saved['error'], $url);
        }
        self::assertSame($page['url'], $this->api('GET', $path)['url']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($invalid as $url) {
            $em->find(Page::class, $page['id'])->translation('ru')->draft['url'] = $url;
            $em->flush();
            $published = $this->api('POST', $path . '/publish', [], 422);
            self::assertSame(I18n::t('content.page_url_invalid'), $published['error'], $url);
        }
        self::assertNull($this->api('GET', $path . '/draft')['published']);
    }

    #[Test]
    public function aDoubleSlashInsideAnAddressCanBeSavedAndPublished(): void
    {
        $this->start();
        $page = $this->page();
        $path = 'pages/' . $page['id'];
        $url = $page['url'] . '//mid';

        self::assertSame($url, $this->api('PUT', $path . '/draft', ['url' => $url])['url']);
        $this->api('POST', $path . '/publish');
        self::assertSame($url, $this->api('GET', $path . '/published')['url']);
    }

    #[Test]
    public function theSitemapAddressIsReserved(): void
    {
        $this->start();
        $page = $this->page();
        $error = I18n::t('content.reserved_url', [
            'url' => '/sitemap.xml',
            'what' => I18n::t('content.reserved_url_sitemap'),
        ]);
        $created = $this->api('POST', 'pages', ['title' => 'Sitemap', 'url' => '/sitemap.xml'], 422);
        self::assertSame($error, $created['error']);
        $updated = $this->api('PUT', 'pages/' . $page['id'] . '/draft', ['url' => '/sitemap.xml'], 422);
        self::assertSame($error, $updated['error']);
    }

    #[Test]
    public function aLegacySecondaryHomeCanMoveOnlyToItsLanguagePrefix(): void
    {
        $this->start();
        $page = $this->page();
        $this->addTranslation($page['id'], 'kk', '/');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(Page::class, $page['id'])->template = 'home';
        $em->flush();
        $path = 'pages/' . $page['id'] . '/draft?locale=kk';

        foreach (['/kk/elsewhere', '/kk/'] as $url) {
            $locked = $this->api('PUT', $path, ['url' => $url], 422);
            self::assertSame(I18n::t('content.page_home_url_locked'), $locked['error']);
        }
        self::assertSame('/kk', $this->api('PUT', $path, ['url' => '/kk'])['url']);
        $locked = $this->api('PUT', $path, ['url' => '/kk/elsewhere'], 422);
        self::assertSame(I18n::t('content.page_home_url_locked'), $locked['error']);
    }

    #[Test]
    public function anOlderSiteWithoutLocalesKeepsItsExistingLanguageAndAddress(): void
    {
        $this->start(false);
        $page = $this->page();
        $this->addTranslation($page['id'], 'zz', '/legacy-address');
        $saved = $this->api('PUT', 'pages/' . $page['id'] . '/draft?locale=zz', ['url' => '/old-address', 'title' => 'Legacy language']);
        self::assertSame('zz', $saved['locale']);
        self::assertSame('/old-address', $saved['url']);
        self::assertSame('Legacy language', $saved['title']);
        self::assertSame(['main' => 'ru', 'list' => [['code' => 'ru', 'name' => 'Русский']]], $this->api('GET', 'languages')); // i18n-ignore: built-in language name
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Security\TwoFactor\SecretBox;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use Itk\Cms\YandexWebmaster\Model\WebmasterConnection;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;
use Itk\Cms\YandexWebmaster\Model\WebmasterVerification;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Код Яндекс Вебмастера стоит на живых страницах рядом с кодом владельца, и ни 304, ни кэш страниц его не прячут. */
final class YandexWebmasterPagesTest extends WebTestCase
{
    use TemporarySettings;

    private const UIN = 'f00dcafe12345678';
    private const OWNER = 'owner-code-1234';

    /** @var list<string> */
    private array $created = [];
    private KernelBrowser $client;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->catchExceptions(false);
        $this->setting('domain', 'localhost'); // the page cache keeps pages only for the host the site vouches for
        self::getContainer()->get(PageCache::class)->clear();
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        foreach ($this->created as $id) {
            try {
                $c->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        $settings = $c->get(SettingsService::class);
        $settings->set('yandexVerification', null);
        $c->get(WebmasterStore::class)->forget();
        $this->restoreSettings();
        $c->get(PageCache::class)->clear();
        parent::tearDown();
    }

    private function publish(string $url): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Вебмастер', 'url' => $url]);
        $this->created[] = $page['id'];
        $pages->publish($page['id']);
    }

    private function connect(?string $uin): void
    {
        $box = self::getContainer()->get(SecretBox::class);
        self::getContainer()->get(WebmasterStore::class)->saveConnection(new WebmasterConnection($box->seal('token'), new \DateTimeImmutable(), 42, 'https:site.example.org:443', 'https://site.example.org', $uin, false));
    }

    /** @return array{0: int, 1: string, 2: string} status, ETag, body */
    private function get(string $url, ?string $etag = null): array
    {
        $this->client->request('GET', $url, server: null === $etag ? [] : ['HTTP_IF_NONE_MATCH' => $etag]);
        $r = $this->client->getResponse();

        return [$r->getStatusCode(), (string) $r->headers->get('ETag'), (string) $r->getContent()];
    }

    #[Test]
    public function theCodeFromYandexIsOnTheLivePageAndAnOldTagDoesNotHideIt(): void
    {
        $this->publish('/test-wm-meta');
        [, $before, $body] = $this->get('/test-wm-meta');
        self::assertStringNotContainsString('yandex-verification', $body);

        $this->connect(self::UIN);
        [$status, $after, $body] = $this->get('/test-wm-meta', $before);

        self::assertSame(200, $status, 'the 304 of the old page does not hide the new tag');
        self::assertNotSame($before, $after);
        self::assertSame(1, \substr_count($body, '<meta name="yandex-verification" content="' . self::UIN . '">'));
    }

    #[Test]
    public function itStandsNextToADifferentCodeOfTheOwnerAndIsNotRepeated(): void
    {
        $this->publish('/test-wm-two');
        self::getContainer()->get(SettingsService::class)->set('yandexVerification', self::OWNER);
        $this->connect(self::UIN);

        foreach ([1, 2] as $_) { // the second time comes from the cache of pages
            [, , $body] = $this->get('/test-wm-two');
            self::assertSame(1, \substr_count($body, 'content="' . self::UIN . '"'));
            self::assertSame(1, \substr_count($body, 'content="' . self::OWNER . '"'));
        }
    }

    #[Test]
    public function theOwnersCodeEqualToTheOneFromYandexIsWrittenOnce(): void
    {
        $this->publish('/test-wm-same');
        self::getContainer()->get(SettingsService::class)->set('yandexVerification', self::UIN);
        $this->connect(self::UIN);

        [, , $body] = $this->get('/test-wm-same');

        self::assertSame(1, \substr_count($body, 'name="yandex-verification"'));
    }

    #[Test]
    public function aRefreshOfTheSnapshotDoesNotChangeTheTagOfThePageButDisconnectingDoes(): void
    {
        $this->publish('/test-wm-etag');
        $this->connect(self::UIN);
        $store = self::getContainer()->get(WebmasterStore::class);
        $store->saveSnapshot(new WebmasterSnapshot(new WebmasterVerification('IN_PROGRESS', self::UIN, null), null, null, new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('-1 hour')));
        [, $etag] = $this->get('/test-wm-etag');

        $store->saveSnapshot(new WebmasterSnapshot(new WebmasterVerification('VERIFIED', self::UIN, null), ['sqi' => 40], null, new \DateTimeImmutable(), new \DateTimeImmutable()));
        [$status] = $this->get('/test-wm-etag', $etag);
        self::assertSame(304, $status, 'a refresh every few hours must not cost the visitors their 304');

        $store->forget();
        [$status, $changed, $body] = $this->get('/test-wm-etag', $etag);
        self::assertSame(200, $status);
        self::assertNotSame($etag, $changed);
        self::assertStringNotContainsString('yandex-verification', $body);
    }

    #[Test]
    public function thePagesOfTheAdminAndTheApiDoNotGetIt(): void
    {
        $this->connect(self::UIN);

        $this->client->request('GET', '/admin/login');
        self::assertStringNotContainsString(self::UIN, (string) $this->client->getResponse()->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\IndexNow\IndexNowState;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** IndexNowSite: выключено, нет адреса, адрес не публичный, нет http-client или готово; ключ получается из адреса и секрета и ничего не хранит. */
final class IndexNowSiteTest extends KernelTestCase
{
    use IndexNowSettings;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        parent::tearDown();
    }

    private function site(?HttpClientInterface $http = new MockHttpClient(), string $secret = 'secret-one'): IndexNowSite
    {
        $c = self::getContainer();

        return new IndexNowSite($c->get(SettingsService::class), $c->get(SitemapBuilder::class), $c->get(CacheItemPoolInterface::class), $secret, $http);
    }

    /** @return iterable<string, array{string, IndexNowState}> */
    public static function addresses(): iterable
    {
        yield 'empty' => ['', IndexNowState::NoAddress];
        yield 'not an address' => ['not an address', IndexNowState::NoAddress];
        yield 'public host' => ['example.org', IndexNowState::Ready];
        yield 'public host with a scheme and a port' => ['https://www.example.org:8443', IndexNowState::Ready];
        yield 'localhost' => ['localhost:8000', IndexNowState::NotPublic];
        yield 'IPv4' => ['192.168.1.20', IndexNowState::NotPublic];
        yield 'a .localhost name' => ['site.localhost', IndexNowState::NotPublic];
        yield 'a .test name' => ['cms.test', IndexNowState::NotPublic];
        yield 'a .local name' => ['nas.local', IndexNowState::NotPublic];
        yield 'a .internal name' => ['app.internal', IndexNowState::NotPublic];
        yield 'a .example name' => ['shop.example', IndexNowState::NotPublic];
        yield 'a .invalid name' => ['shop.invalid', IndexNowState::NotPublic];
        yield 'a name that only ends like a zone' => ['attest.org', IndexNowState::Ready];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function theStateFollowsTheAddressOfTheSite(string $domain, IndexNowState $expected): void
    {
        $site = $this->site();
        $this->setting('indexNow', true);
        $this->setting('domain', $domain);

        self::assertSame($expected, $site->state());
    }

    #[Test]
    public function withTheToggleOffNothingElseMatters(): void
    {
        $site = $this->site();
        $this->setting('domain', 'example.org');
        $this->setting('indexNow', false);

        self::assertSame(IndexNowState::Off, $site->state());
    }

    #[Test]
    public function withoutAnHttpClientTheSiteSaysSo(): void
    {
        $site = $this->site(null);
        $this->setting('indexNow', true);
        $this->setting('domain', 'example.org');

        self::assertSame(IndexNowState::NoHttpClient, $site->state());
    }

    #[Test]
    public function theKeyIs32HexCharactersAndFollowsTheAddressAndTheSecretOnly(): void
    {
        $this->setting('domain', 'example.org');
        $one = $this->site();
        $key = $one->key();

        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $key);
        self::assertSame($key, $this->site()->key(), 'the same on every request and server');
        self::assertNotSame($key, $this->site(new MockHttpClient(), 'secret-two')->key(), 'another secret, another key');
        self::assertSame('https://example.org/' . $key . '.txt', $one->keyLocation());
        self::assertSame('example.org', $one->host());

        $this->setting('domain', 'https://other.example.org:8443');
        self::assertNotSame($key, $one->key(), 'another address, another key');
        self::assertSame('other.example.org', $one->host(), 'the host has no port');
    }

    #[Test]
    public function theLastSubmissionIsKeptForTheHealthLine(): void
    {
        $site = $this->site();
        $site->recordSubmission(3, 202);

        $last = $site->lastSubmission();

        self::assertSame([3, 202, null], [$last['count'], $last['status'], $last['error']]);
        self::assertEqualsWithDelta(\time(), (new \DateTimeImmutable($last['at']))->getTimestamp(), 5);
    }
}

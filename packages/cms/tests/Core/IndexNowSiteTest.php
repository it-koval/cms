<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\Setting;
use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\IndexNow\IndexNowState;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** IndexNowSite: выключено, нет адреса, адрес не публичный, нет http-client или готово; ключ получается из адреса и секрета и ничего не хранит. */
final class IndexNowSiteTest extends KernelTestCase
{
    use IndexNowSettings;

    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-11-06 10:00:00');
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
        parent::tearDown();
    }

    private function site(?HttpClientInterface $http = new MockHttpClient(), string $secret = 'secret-one'): IndexNowSite
    {
        $c = self::getContainer();

        return new IndexNowSite($c->get(SettingsService::class), $c->get(SitemapBuilder::class), $c->get(CacheItemPoolInterface::class), $this->clock, $secret, $http);
    }

    /** @return iterable<string, array{string, IndexNowState}> */
    public static function addresses(): iterable
    {
        yield 'empty' => ['', IndexNowState::NoAddress];
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
    public function anInvalidDomainStoredBeforeValidationIsIgnored(): void
    {
        $this->setting('indexNow', true);
        $this->setting('domain', '');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(Setting::class, ['key' => 'domain', 'locale' => Setting::ALL]);
        self::assertNotNull($row);
        $row->value = 'not an address';
        $em->flush();
        self::getContainer()->get(SettingsService::class)->reset();

        self::assertSame(IndexNowState::NoAddress, $this->site()->state());
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
        self::assertSame('example.org', $one->address()->host);

        $this->setting('domain', 'https://other.example.org:8443');
        self::assertNotSame($key, $one->key(), 'another address, another key');
        self::assertSame('other.example.org', $one->address()->host, 'the host has no port');
    }

    #[Test]
    public function aCyrillicAddressGoesOutInPunycode(): void
    {
        $this->setting('indexNow', true);
        $this->setting('domain', 'https://Пример-Сайта.рф');
        $site = $this->site();

        self::assertSame(IndexNowState::Ready, $site->state());
        self::assertSame('https://xn----8sbarojrwjdmo.xn--p1ai', $site->address()->origin());
        self::assertSame('xn----8sbarojrwjdmo.xn--p1ai', $site->address()->host);
        self::assertSame('https://xn----8sbarojrwjdmo.xn--p1ai/' . $site->key() . '.txt', $site->keyLocation());
    }

    #[Test]
    public function theLastSubmissionIsKeptForTheHealthLine(): void
    {
        $site = $this->site();
        $site->recordSubmission(3, 202);

        $last = $site->lastSubmission();

        self::assertSame([3, 202, null], [$last['count'], $last['status'], $last['error']]);
        self::assertEquals($this->clock->now(), new \DateTimeImmutable($last['at']));
    }
}

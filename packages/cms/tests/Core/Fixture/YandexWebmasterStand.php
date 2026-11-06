<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Security\TwoFactor\SecretBox;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\YandexWebmaster\WebmasterClient;
use Itk\Cms\YandexWebmaster\WebmasterService;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * A stand-in for Yandex Webmaster: answers are given by «METHOD /path» (the part of the address after /v4, the host id decoded), every call is remembered with its query
 * and the Authorization header, and a call nobody answered fails the test.
 */
trait YandexWebmasterStand
{
    public const TOKEN = 'y0_AgAAAA-secret-token-9d8f7e';
    public const SITE = 'wm.example.org';
    public const HOST = 'https:wm.example.org:443';
    public const HOST_PATH = '/user/42/hosts/' . self::HOST;
    public const UIN = 'f00dcafe12345678';

    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    private array $answers = [];
    /** @var list<string> "METHOD /path?query" */
    private array $calls = [];
    /** @var list<string> */
    private array $authorizations = [];
    /** @var array<string, array<string, mixed>> the JSON body of the last call, by «METHOD /path» */
    private array $bodies = [];
    private bool $networkDown = false;
    /** @var ?\Closure(): void runs on every call, before the answer */
    private ?\Closure $onCall = null;
    /** @var array<string, array{0: bool, 1: mixed}> */
    private array $settingsBefore = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', ['http_client' => ['enabled' => true]]);
    }

    private function mock(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $parts = \parse_url($url);
            $path = \rawurldecode(\substr((string) $parts['path'], \strlen('/v4')));
            $this->calls[] = $method . ' ' . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $this->authorizations[] = \preg_replace('/^Authorization: /i', '', $options['normalized_headers']['authorization'][0] ?? '');
            $this->bodies[$method . ' ' . $path] = \json_decode((string) ($options['body'] ?? ''), true) ?: [];
            if (null !== $this->onCall) {
                ($this->onCall)();
            }
            if ($this->networkDown) {
                return new MockResponse('', ['error' => 'connection refused']);
            }
            $answer = $this->answers[$method . ' ' . $path] ?? null;
            Assert::assertNotNull($answer, 'nobody answers ' . $method . ' ' . $path);

            return new MockResponse(\json_encode($answer[1], \JSON_THROW_ON_ERROR), ['http_code' => $answer[0], 'response_headers' => ['Content-Type: application/json']]);
        });
    }

    /** @param array<string, mixed> $body */
    private function answer(string $key, int $status, array $body = []): void
    {
        $this->answers[$key] = [$status, $body];
    }

    /** What an owner with a token and a fresh site gets from Yandex: the host is not there yet, the check has not started. */
    private function yandexKnowsNothing(): void
    {
        $this->answer('GET /user', 200, ['user_id' => 42]);
        $this->answer('GET /user/42/hosts', 200, ['hosts' => []]);
        $this->answer('POST /user/42/hosts', 201, ['host_id' => self::HOST]);
        $this->answer('GET ' . self::HOST_PATH . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'NONE', 'verification_type' => 'NONE']);
        $this->answer('POST ' . self::HOST_PATH . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'IN_PROGRESS', 'verification_type' => 'META_TAG']);
    }

    private function yandexVerified(): void
    {
        $this->answer('GET ' . self::HOST_PATH . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'VERIFIED', 'verification_type' => 'META_TAG']);
        $this->answer('POST ' . self::HOST_PATH . '/user-added-sitemaps', 201, ['sitemap_id' => 'abc']);
        $this->answer('GET ' . self::HOST_PATH . '/summary', 200, ['sqi' => 40, 'excluded_pages_count' => 7, 'searchable_pages_count' => 120, 'site_problems' => ['CRITICAL' => 1, 'POSSIBLE_PROBLEM' => 3, 'RECOMMENDATION' => 5]]);
        $this->answer('GET ' . self::HOST_PATH . '/search-queries/popular', 200, [
            'date_from' => '2026-10-30', 'date_to' => '2026-11-05', 'count' => 2,
            'queries' => [
                ['query_id' => 'a', 'query_text' => 'веб-студия томск', 'indicators' => ['TOTAL_SHOWS' => 120.0, 'TOTAL_CLICKS' => 14.0]],
                ['query_id' => 'b', 'query_text' => 'сайт под ключ', 'indicators' => ['TOTAL_SHOWS' => 80.0]],
            ],
        ]);
    }

    private function webmasterService(?MockHttpClient $http = null): WebmasterService
    {
        $c = self::getContainer();

        return new WebmasterService(new WebmasterClient($http ?? $this->mock(), 'https://api.webmaster.test/v4'), $c->get(WebmasterStore::class), $c->get(SitemapBuilder::class), $c->get(SecretBox::class), 'client-id-1');
    }

    private function setting(string $key, mixed $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $this->settingsBefore[$key] ??= [$settings->isSet($key), $settings->get($key)];
        $settings->set($key, $value);
    }

    private function siteAt(string $domain = self::SITE): void
    {
        $this->setting('domain', $domain);
    }

    private function resetWebmaster(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        self::getContainer()->get(WebmasterStore::class)->forget();
        foreach ($this->settingsBefore as $key => [$wasSet, $value]) {
            if ($wasSet) {
                $settings->set($key, $value);
            } else {
                $settings->remove($key);
            }
        }
        $this->settingsBefore = [];
    }

    /** @return list<string> the calls made so far, without their query */
    private function callsWithoutQuery(): array
    {
        return \array_map(static fn (string $call): string => \explode('?', $call)[0], $this->calls);
    }
}

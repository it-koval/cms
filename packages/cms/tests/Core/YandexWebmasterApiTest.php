<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\YandexWebmasterStand;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Админский API Яндекс Вебмастера: права set:analytics, коды ответов, форма тела, токен нигде не виден. */
final class YandexWebmasterApiTest extends WebTestCase
{
    use YandexWebmasterStand;

    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::getContainer()->set('http_client', $this->mock());
        $this->siteAt();
    }

    protected function tearDown(): void
    {
        $this->resetWebmaster();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-wm-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key = :k')->setParameter('k', 'test-wm-view')->execute();
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        /** @var User $user */
        $user = self::getContainer()->get(UserManager::class)->create('test-wm-' . $role . '@example.com', 'Тест ' . $role, $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    private function viewerRole(): string
    {
        $role = new Role('test-wm-view', 'Test смотрит');
        $role->perms = ['set:analytics' => ['view']];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();

        return 'test-wm-view';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getContent(), 'the token is in no answer');

        return (array) \json_decode((string) $response->getContent(), true);
    }

    #[Test]
    public function beforeAnythingIsConnectedTheStatusAsksForAToken(): void
    {
        $this->signIn('admin');
        $status = $this->api('GET', 'yandex-webmaster');

        self::assertSame(['state' => 'not_connected', 'canManage' => true, 'tokenLink' => null], \array_intersect_key($status, \array_flip(['state', 'canManage', 'tokenLink'])));
        foreach (['connectedAt', 'tokenExpiresAt', 'host', 'webmasterUrl', 'verification', 'summary', 'queries', 'updatedAt', 'error'] as $key) {
            self::assertArrayHasKey($key, $status);
            self::assertNull($status[$key], $key);
        }
        self::assertFalse($status['tokenExpiresSoon']);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function theTokenLinkComesFromTheEnvironmentOfTheServer(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['ITK_YANDEX_CLIENT_ID'] = 'app-id-7';
        try {
            $this->client = static::createClient();
            $this->client->disableReboot();
            $this->signIn('admin');

            self::assertSame('https://oauth.yandex.ru/authorize?response_type=token&client_id=app-id-7', $this->api('GET', 'yandex-webmaster')['tokenLink']);
        } finally {
            unset($_SERVER['ITK_YANDEX_CLIENT_ID']);
        }
    }

    #[Test]
    public function connectingGivesTheWholeStatusInTheShapeOfTheContract(): void
    {
        $this->signIn('admin');
        $this->yandexKnowsNothing();
        $this->yandexVerified();

        $status = $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN]);

        self::assertSame(['state', 'canManage', 'tokenLink', 'connectedAt', 'tokenExpiresAt', 'tokenExpiresSoon', 'host', 'webmasterUrl', 'verification', 'summary', 'queries', 'updatedAt', 'error'], \array_keys($status));
        self::assertSame('ready', $status['state']);
        self::assertSame(['id' => self::HOST, 'url' => 'https://' . self::SITE], $status['host']);
        self::assertSame(['state' => 'VERIFIED', 'failReason' => null], $status['verification']);
        self::assertSame(40, $status['summary']['sqi']);
        self::assertSame(['text' => 'веб-студия томск', 'shows' => 120, 'clicks' => 14], $status['queries']['rows'][0]);
        self::assertSame((new \DateTimeImmutable($status['connectedAt']))->modify('+180 days')->format(\DATE_ATOM), $status['tokenExpiresAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $status['updatedAt']);
        $this->calls = [];
        self::assertEquals($status, $this->api('GET', 'yandex-webmaster'), 'a read right after is the same');
        self::assertSame([], $this->calls, 'and asks nobody');
    }

    #[Test]
    public function aRefusedTokenIs422WithAnErrorAndNothingIsKept(): void
    {
        $this->signIn('admin');
        $this->answer('GET /user', 403, ['error_code' => 'INVALID_OAUTH_TOKEN']);

        $error = $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN], 422);
        self::assertArrayHasKey('error', $error);
        self::assertSame('not_connected', $this->api('GET', 'yandex-webmaster')['state']);

        self::assertArrayHasKey('error', $this->api('POST', 'yandex-webmaster/connect', ['token' => ''], 422));
        self::assertArrayHasKey('error', $this->api('POST', 'yandex-webmaster/connect', [], 422));
        self::assertArrayHasKey('error', $this->api('POST', 'yandex-webmaster/connect', ['token' => ['x']], 422));
    }

    #[Test]
    public function aSiteWithoutAPublicAddressIs422(): void
    {
        $this->signIn('admin');
        $this->siteAt('localhost:8000');

        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN], 422);
        self::assertSame('no_address', $this->api('GET', 'yandex-webmaster')['state']);
    }

    #[Test]
    public function yandexNotAnsweringIs502(): void
    {
        $this->signIn('admin');
        $this->networkDown = true;

        self::assertArrayHasKey('error', $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN], 502));
    }

    #[Test]
    public function refreshAsksYandexNowAndDisconnectingForgetsTheToken(): void
    {
        $this->signIn('admin');
        $this->yandexKnowsNothing();
        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN]);
        $this->yandexVerified();
        $this->calls = [];

        $status = $this->api('POST', 'yandex-webmaster/refresh');
        self::assertSame('ready', $status['state']);
        self::assertNotSame([], $this->calls);

        $gone = $this->api('DELETE', 'yandex-webmaster');
        self::assertSame(['not_connected', null, null, null], [$gone['state'], $gone['host'], $gone['summary'], $gone['connectedAt']]);
        $this->calls = [];
        self::assertSame('not_connected', $this->api('GET', 'yandex-webmaster')['state']);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function yandexIsAskedWithTheSessionClosedSoTheOtherCallsOfTheAdminDoNotWait(): void
    {
        $this->signIn('admin');
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $requests = self::getContainer()->get('request_stack');
        $sessionOpen = [];
        $this->onCall = static function () use ($requests, &$sessionOpen): void {
            $sessionOpen[] = $requests->getMainRequest()?->getSession()->isStarted();
        };

        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN]);
        $this->api('POST', 'yandex-webmaster/refresh');
        self::getContainer()->get(WebmasterStore::class)->saveSnapshot(new WebmasterSnapshot());
        $this->api('GET', 'yandex-webmaster');

        self::assertGreaterThan(10, \count($sessionOpen), 'connect, refresh and a read of an old snapshot all asked Yandex');
        self::assertSame([false], \array_values(\array_unique($sessionOpen, \SORT_REGULAR)));
        self::assertSame('ready', $this->api('GET', 'yandex-webmaster')['state'], 'the person is still signed in');
    }

    #[Test]
    public function aPersonWithoutTheRightToTheGroupGets403OnEveryCall(): void
    {
        $this->signIn('editor');

        $this->api('GET', 'yandex-webmaster', [], 403);
        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN], 403);
        $this->api('POST', 'yandex-webmaster/refresh', [], 403);
        $this->api('DELETE', 'yandex-webmaster', [], 403);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function aPersonWhoMayOnlyViewSeesTheStatusAndRefreshesButCannotConnectOrDisconnect(): void
    {
        $this->signIn('admin');
        $this->yandexKnowsNothing();
        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN]);
        $this->signIn($this->viewerRole());

        $status = $this->api('GET', 'yandex-webmaster');
        self::assertFalse($status['canManage']);
        self::assertSame(self::HOST, $status['host']['id']);
        $this->yandexVerified();
        self::assertSame('ready', $this->api('POST', 'yandex-webmaster/refresh')['state']);
        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN], 403);
        $this->api('DELETE', 'yandex-webmaster', [], 403);
        self::assertSame('ready', $this->api('GET', 'yandex-webmaster')['state'], 'nothing was disconnected');
    }

    #[Test]
    public function aWriteWithoutTheCsrfTokenIsRefused(): void
    {
        $this->signIn('admin');
        $this->csrf = 'wrong';

        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN], 403);
        $this->api('DELETE', 'yandex-webmaster', [], 403);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function theTokenOrItsStorageNeverShowsInTheSettingsApi(): void
    {
        $this->signIn('admin');
        $this->yandexKnowsNothing();
        $this->api('POST', 'yandex-webmaster/connect', ['token' => self::TOKEN]);

        $settings = $this->api('GET', 'settings');
        self::assertArrayNotHasKey('yandexWebmaster', $settings);
        self::assertArrayNotHasKey('yandexWebmasterSnapshot', $settings);

        $merged = $this->api('PATCH', 'settings', ['yandexVerification' => 'owner-code-1234']);
        self::assertArrayNotHasKey('yandexWebmaster', $merged);
        $this->api('PATCH', 'settings', ['yandexVerification' => '']);
    }
}

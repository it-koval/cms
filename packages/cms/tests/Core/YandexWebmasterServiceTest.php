<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Security\TwoFactor\SecretBox;
use Itk\Cms\Tests\Core\Fixture\YandexWebmasterStand;
use Itk\Cms\YandexWebmaster\Model\WebmasterConnection;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;
use Itk\Cms\YandexWebmaster\Model\WebmasterState;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** WebmasterService: подключение по токену, поиск или добавление сайта, проверка метатегом, обновление снимка не чаще раза в 6 часов, отключение. */
final class YandexWebmasterServiceTest extends KernelTestCase
{
    use YandexWebmasterStand;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->siteAt();
    }

    protected function tearDown(): void
    {
        $this->resetWebmaster();
        parent::tearDown();
    }

    #[Test]
    public function withoutATokenTheStatusIsNotConnectedAndYandexIsNotAsked(): void
    {
        $status = $this->webmasterService()->status();

        self::assertSame(WebmasterState::NotConnected, $status->state);
        self::assertSame('https://oauth.yandex.ru/authorize?response_type=token&client_id=client-id-1', $status->tokenLink);
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function aSiteThatIsNotInWebmasterYetIsAddedAndItsOwnershipCheckStartsWithTheMetaTag(): void
    {
        $this->yandexKnowsNothing();
        $status = $this->webmasterService()->connect('  ' . self::TOKEN . ' ');

        self::assertSame(WebmasterState::Verifying, $status->state);
        self::assertSame(['id' => self::HOST, 'url' => 'https://' . self::SITE], $status->host);
        self::assertSame('IN_PROGRESS', $status->verification?->state);
        self::assertSame(['GET /user', 'GET /user/42/hosts', 'POST /user/42/hosts', 'GET ' . self::HOST_PATH . '/verification', 'POST ' . self::HOST_PATH . '/verification'], $this->callsWithoutQuery());
        self::assertContains('POST ' . self::HOST_PATH . '/verification?verification_type=META_TAG', $this->calls);
        self::assertSame(['OAuth ' . self::TOKEN], \array_unique($this->authorizations), 'the token is trimmed and sent in the header only');
        self::assertSame(self::UIN, self::getContainer()->get(WebmasterStore::class)->uin());
    }

    #[Test]
    public function theCodeIsStoredBeforeYandexIsAskedToLookForIt(): void
    {
        $this->yandexKnowsNothing();
        $store = self::getContainer()->get(WebmasterStore::class);
        $seenAtStart = null;
        $http = $this->mock();
        $this->webmasterService(new MockHttpClient(static function (string $method, string $url, array $options) use ($http, $store, &$seenAtStart): ResponseInterface {
            if ('POST' === $method && \str_ends_with($url, '/verification?verification_type=META_TAG')) {
                $seenAtStart = $store->uin();
            }

            return $http->request($method, $url, $options);
        }))->connect(self::TOKEN);

        self::assertSame(self::UIN, $seenAtStart);
    }

    #[Test]
    public function aSiteThatIsAlreadyListedIsFoundByItsAddressInAnyCase(): void
    {
        $this->yandexKnowsNothing();
        $this->answer('GET /user/42/hosts', 200, ['hosts' => [
            ['host_id' => 'http:other.example.org:80', 'ascii_host_url' => 'http://other.example.org/'],
            ['host_id' => self::HOST, 'ascii_host_url' => 'HTTPS://WM.example.org:443/'],
        ]]);
        $status = $this->webmasterService()->connect(self::TOKEN);

        self::assertSame(self::HOST, $status->host['id'] ?? null);
        self::assertNotContains('POST /user/42/hosts', $this->callsWithoutQuery());
    }

    #[Test]
    public function aSiteYandexSaysIsAlreadyAddedGivesItsId(): void
    {
        $this->yandexKnowsNothing();
        $this->answer('POST /user/42/hosts', 409, ['error_code' => 'HOST_ALREADY_ADDED', 'host_id' => self::HOST, 'verified' => false]);
        $status = $this->webmasterService()->connect(self::TOKEN);

        self::assertSame(self::HOST, $status->host['id'] ?? null);
    }

    #[Test]
    public function aCheckThatIsAlreadyRunningIsNoError(): void
    {
        $this->yandexKnowsNothing();
        $this->answer('POST ' . self::HOST_PATH . '/verification', 409, ['error_code' => 'VERIFICATION_ALREADY_IN_PROGRESS']);
        $status = $this->webmasterService()->connect(self::TOKEN);

        self::assertSame([WebmasterState::Verifying, null], [$status->state, $status->error]);
    }

    #[Test]
    public function aRefusedTokenIsAnErrorForTheOwnerAndNothingIsStored(): void
    {
        $this->answer('GET /user', 403, ['error_code' => 'INVALID_OAUTH_TOKEN', 'error_message' => 'bad']);

        try {
            $this->webmasterService()->connect(self::TOKEN);
            self::fail('expected the token to be refused');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        self::assertNull(self::getContainer()->get(WebmasterStore::class)->connection());
        self::assertNull(self::getContainer()->get(SettingsService::class)->stored(WebmasterStore::CONNECTION));
    }

    #[Test]
    public function aTokenAnswered401IsRefusedToo(): void
    {
        $this->answer('GET /user', 401, []);

        $this->expectException(ContentException::class);
        $this->webmasterService()->connect(self::TOKEN);
    }

    #[Test]
    public function anEmptyTokenIsRefusedWithoutAskingYandex(): void
    {
        try {
            $this->webmasterService()->connect('   ');
            self::fail('expected an empty token to be refused');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function yandexNotAnsweringIsA502AndNothingIsStored(): void
    {
        $this->networkDown = true;

        try {
            $this->webmasterService()->connect(self::TOKEN);
            self::fail('expected a failure');
        } catch (ContentException $e) {
            self::assertSame(502, $e->status());
        }
        self::assertNull(self::getContainer()->get(WebmasterStore::class)->connection());
    }

    #[Test]
    public function aFailureOfYandexOnTheWayIs502AndABadRequestIs422(): void
    {
        $this->answer('GET /user', 200, ['user_id' => 42]);
        $this->answer('GET /user/42/hosts', 503, []);
        try {
            $this->webmasterService()->connect(self::TOKEN);
            self::fail('expected a failure');
        } catch (ContentException $e) {
            self::assertSame(502, $e->status());
        }

        $this->answer('GET /user/42/hosts', 200, ['hosts' => []]);
        $this->answer('POST /user/42/hosts', 403, ['error_code' => 'HOSTS_LIMIT_EXCEEDED']);
        try {
            $this->webmasterService()->connect(self::TOKEN);
            self::fail('expected a refusal');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
            self::assertStringContainsString('HOSTS_LIMIT_EXCEEDED', $e->getMessage());
        }
    }

    #[Test]
    public function aSiteWithoutAPublicAddressCannotBeConnected(): void
    {
        foreach (['', 'localhost:8000', '192.168.1.5', 'cms.test'] as $domain) {
            $this->siteAt($domain);
            try {
                $this->webmasterService()->connect(self::TOKEN);
                self::fail('expected a refusal for "' . $domain . '"');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
            self::assertSame(WebmasterState::NoAddress, $this->webmasterService()->status()->state);
        }
        self::assertSame([], $this->calls);
    }

    #[Test]
    public function aCyrillicAddressGoesToYandexInPunycodeAndTheOwnerSeesItAsWritten(): void
    {
        $this->siteAt('северный-свет.рф');
        $ascii = 'xn----ctbbjbcz5a0agho5i.xn--p1ai';
        $host = 'https:' . $ascii . ':443';
        $this->answer('GET /user', 200, ['user_id' => 42]);
        $this->answer('GET /user/42/hosts', 200, ['hosts' => []]);
        $this->answer('POST /user/42/hosts', 201, ['host_id' => $host]);
        $this->answer('GET /user/42/hosts/' . $host . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'NONE']);
        $this->answer('POST /user/42/hosts/' . $host . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'IN_PROGRESS']);

        $status = $this->webmasterService()->connect(self::TOKEN);

        self::assertSame(['host_url' => 'https://' . $ascii], $this->bodies['POST /user/42/hosts']);
        self::assertSame(['id' => $host, 'url' => 'https://северный-свет.рф'], $status->host);
    }

    #[Test]
    public function aVerifiedSiteGetsItsSitemapOnceAndItsNumbers(): void
    {
        $this->yandexKnowsNothing();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->yandexVerified();
        $this->calls = [];

        $status = $service->status(true);

        self::assertSame(WebmasterState::Ready, $status->state);
        self::assertSame(['sqi' => 40, 'searchable' => 120, 'excluded' => 7, 'problems' => ['FATAL' => 0, 'CRITICAL' => 1, 'POSSIBLE_PROBLEM' => 3, 'RECOMMENDATION' => 5]], $status->summary);
        self::assertSame(
            ['from' => '2026-10-30', 'to' => '2026-11-05', 'rows' => [['text' => 'веб-студия томск', 'shows' => 120, 'clicks' => 14], ['text' => 'сайт под ключ', 'shows' => 80, 'clicks' => null]]],
            $status->queries,
        );
        self::assertContains('POST ' . self::HOST_PATH . '/user-added-sitemaps', $this->calls);
        self::assertContains('GET ' . self::HOST_PATH . '/search-queries/popular?order_by=TOTAL_SHOWS&query_indicator=TOTAL_SHOWS&query_indicator=TOTAL_CLICKS&limit=10', $this->calls);
        self::assertSame('https://webmaster.yandex.ru/site/' . self::HOST . '/dashboard/', $status->webmasterUrl);
        self::assertNull($status->error);

        $this->calls = [];
        $service->status(true);
        self::assertNotContains('POST ' . self::HOST_PATH . '/user-added-sitemaps', $this->calls, 'the sitemap is registered once');
    }

    #[Test]
    public function theSitemapAddressIsTheOneOfTheSite(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $this->webmasterService()->connect(self::TOKEN);

        self::assertSame(['url' => 'https://' . self::SITE . '/sitemap.xml'], $this->bodies['POST ' . self::HOST_PATH . '/user-added-sitemaps']);
    }

    #[Test]
    public function aSitemapYandexAlreadyHasIsFine(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $this->answer('POST ' . self::HOST_PATH . '/user-added-sitemaps', 409, ['error_code' => 'SITEMAP_ALREADY_ADDED']);

        $status = $this->webmasterService()->connect(self::TOKEN);

        self::assertSame([WebmasterState::Ready, null], [$status->state, $status->error]);
    }

    #[Test]
    public function dataYandexHasNotLoadedYetIsNullAndNotAnError(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $this->answer('GET ' . self::HOST_PATH . '/summary', 404, ['error_code' => 'HOST_NOT_LOADED']);
        $this->answer('GET ' . self::HOST_PATH . '/search-queries/popular', 404, ['error_code' => 'HOST_NOT_INDEXED']);

        $status = $this->webmasterService()->connect(self::TOKEN);

        self::assertSame(WebmasterState::Ready, $status->state);
        self::assertNull($status->summary);
        self::assertNull($status->queries);
        self::assertNull($status->error);
        self::assertNotNull($status->updatedAt);
    }

    #[Test]
    public function aFailedCheckShowsItsReasonAndOnlyAForcedRefreshStartsItAgain(): void
    {
        $this->yandexKnowsNothing();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->answer('GET ' . self::HOST_PATH . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'VERIFICATION_FAILED', 'fail_info' => ['reason' => 'META_TAG_NOT_FOUND', 'message' => 'x']]);
        $this->makeStale();
        $this->calls = [];

        $status = $service->status();

        self::assertSame([WebmasterState::VerificationFailed, 'META_TAG_NOT_FOUND'], [$status->state, $status->verification?->failReason]);
        self::assertNotContains('POST ' . self::HOST_PATH . '/verification', $this->callsWithoutQuery(), 'a failure is shown, not retried behind the owner\'s back');

        $service->status(true);
        self::assertContains('POST ' . self::HOST_PATH . '/verification', $this->callsWithoutQuery());
    }

    #[Test]
    public function aCheckThatNeverStartedIsStartedAgainByTheNextRefresh(): void
    {
        $this->yandexKnowsNothing();
        $this->answer('POST ' . self::HOST_PATH . '/verification', 422, ['error_code' => 'SOMETHING']);
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->answer('POST ' . self::HOST_PATH . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'IN_PROGRESS']);
        $this->makeStale();
        $this->calls = [];

        $status = $service->status();

        self::assertContains('POST ' . self::HOST_PATH . '/verification', $this->callsWithoutQuery());
        self::assertSame('IN_PROGRESS', $status->verification?->state);
    }

    #[Test]
    public function withinSixHoursYandexIsNotAskedUnlessTheOwnerForcesIt(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->calls = [];

        $service->status();
        $service->status();
        self::assertSame([], $this->calls);

        $service->status(true);
        self::assertNotSame([], $this->calls);

        $this->makeStale('-7 hours');
        $this->calls = [];
        $service->status();
        self::assertNotSame([], $this->calls, 'a snapshot older than 6 hours is renewed by a plain read');
    }

    #[Test]
    public function aRefusedTokenOnRefreshIsTokenInvalidAndTheLastNumbersStay(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->answer('GET ' . self::HOST_PATH . '/verification', 403, ['error_code' => 'INVALID_OAUTH_TOKEN']);

        $status = $service->status(true);

        self::assertSame(WebmasterState::TokenInvalid, $status->state);
        self::assertSame(40, $status->summary['sqi'] ?? null);
        self::assertNull($status->error);

        $this->calls = [];
        $service->status(true);
        self::assertSame([], $this->calls, 'a token Yandex refused is not tried again until the owner pastes a new one');

        $this->yandexVerified();
        self::assertSame(WebmasterState::Ready, $service->connect(self::TOKEN)->state);
    }

    #[Test]
    public function aFailureOnRefreshKeepsTheLastNumbersAndSaysSoInWords(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->networkDown = true;

        $status = $service->status(true);

        self::assertSame(WebmasterState::Ready, $status->state);
        self::assertSame(120, $status->summary['searchable'] ?? null);
        self::assertSame('Яндекс Вебмастер не ответил. Данные обновятся при следующей попытке', $status->error);

        $this->calls = [];
        $service->status();
        self::assertSame([], $this->calls, 'a failed refresh is retried after 15 minutes, not on every read');

        $this->networkDown = false;
        $this->makeStale('-16 minutes');
        $recovered = $service->status();
        self::assertNull($recovered->error);
    }

    #[Test]
    public function aServerErrorOfYandexIsAWordedErrorToo(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->answer('GET ' . self::HOST_PATH . '/summary', 500, ['error_code' => 'INTERNAL_ERROR']);

        $status = $service->status(true);

        self::assertStringContainsString('INTERNAL_ERROR', (string) $status->error);
        self::assertSame(WebmasterState::Ready, $status->state);
        self::assertSame(120, $status->summary['searchable'] ?? null, 'the summary of the last good refresh stays');
    }

    #[Test]
    public function theTokenIsGoodForSixMonthsAndTheOwnerIsWarnedTwoWeeksBefore(): void
    {
        $this->yandexKnowsNothing();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $store = self::getContainer()->get(WebmasterStore::class);
        $connection = $store->connection();
        self::assertNotNull($connection);

        $fresh = $service->view();
        self::assertEquals($connection->connectedAt->modify('+180 days'), $fresh->tokenExpiresAt);
        self::assertFalse($fresh->tokenExpiresSoon);

        foreach (['-165 days' => false, '-167 days' => true, '-181 days' => true] as $age => $soon) {
            $this->storeConnectedAt($age);
            $view = $service->view();
            self::assertSame($soon, $view->tokenExpiresSoon, $age);
        }
    }

    #[Test]
    public function disconnectingForgetsEverythingAndKeepsTheSiteInWebmaster(): void
    {
        $this->yandexKnowsNothing();
        $this->yandexVerified();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->calls = [];

        $status = $service->disconnect();

        self::assertSame(WebmasterState::NotConnected, $status->state);
        self::assertNull($status->host);
        self::assertNull($status->summary);
        self::assertNull(self::getContainer()->get(WebmasterStore::class)->uin());
        self::assertEquals(new WebmasterSnapshot(), self::getContainer()->get(WebmasterStore::class)->snapshot());
        self::assertSame([], $this->calls, 'the site is not removed from Webmaster');
    }

    #[Test]
    public function aDisconnectWhileYandexAnswersARefreshIsNotUndoneByIt(): void
    {
        $this->yandexKnowsNothing();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->yandexVerified();
        $store = self::getContainer()->get(WebmasterStore::class);
        $this->onCall = function () use ($store): void {
            $this->onCall = null;
            $store->forget();
        };

        $service->status(true);

        $after = $service->view();
        self::assertSame([WebmasterState::NotConnected, null, null], [$after->state, $after->summary, $store->uin()]);
    }

    #[Test]
    public function aNewTokenWhileYandexAnswersARefreshIsNotOverwrittenByTheOldOne(): void
    {
        $this->yandexKnowsNothing();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $this->yandexVerified();
        $store = self::getContainer()->get(WebmasterStore::class);
        $box = self::getContainer()->get(SecretBox::class);
        $this->onCall = function () use ($store, $box): void {
            $this->onCall = null;
            $store->saveConnection(new WebmasterConnection($box->seal('y0_the-new-token'), new \DateTimeImmutable(), 42, self::HOST, 'https://' . self::SITE, null, false));
        };

        $service->status(true);

        $connection = $store->connection();
        self::assertSame('y0_the-new-token', null === $connection ? null : $box->open($connection->sealedToken));
        self::assertFalse($connection->sitemapAdded, 'the old refresh wrote nothing over the new connection');
    }

    #[Test]
    public function theTokenIsStoredSealedAndOnlyThroughTheStore(): void
    {
        $this->yandexKnowsNothing();
        $this->webmasterService()->connect(self::TOKEN);
        $settings = self::getContainer()->get(SettingsService::class);

        self::assertStringNotContainsString(self::TOKEN, (string) \json_encode($settings->stored(WebmasterStore::CONNECTION)));
        self::assertStringNotContainsString(self::TOKEN, (string) \json_encode(self::getContainer()->get(WebmasterStore::class)->snapshot()->toArray()));
        self::assertArrayNotHasKey(WebmasterStore::CONNECTION, $settings->all());
        $raw = self::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->fetchOne('SELECT setting_value::text FROM itk_setting WHERE setting_key = ?', [WebmasterStore::CONNECTION]);
        self::assertStringNotContainsString(self::TOKEN, (string) $raw);
    }

    #[Test]
    public function aTokenThatCannotBeOpenedAnyMoreIsAnInvalidToken(): void
    {
        $this->yandexKnowsNothing();
        $service = $this->webmasterService();
        $service->connect(self::TOKEN);
        $store = self::getContainer()->get(WebmasterStore::class);
        $connection = $store->connection();
        self::assertNotNull($connection);
        self::getContainer()->get(SettingsService::class)->set(WebmasterStore::CONNECTION, ['token' => 'v1.broken'] + $connection->toArray());

        self::assertSame(WebmasterState::TokenInvalid, $service->status(true)->state);
    }

    /** The last refresh was this long ago. */
    private function makeStale(string $age = '-7 hours'): void
    {
        $store = self::getContainer()->get(WebmasterStore::class);
        $snapshot = $store->snapshot();
        $store->saveSnapshot(new WebmasterSnapshot($snapshot->verification, $snapshot->summary, $snapshot->queries, $snapshot->updatedAt, new \DateTimeImmutable($age), $snapshot->errorKey, $snapshot->errorCode, $snapshot->tokenInvalid));
    }

    private function storeConnectedAt(string $age): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $stored = $settings->stored(WebmasterStore::CONNECTION);
        self::assertIsArray($stored);
        $stored['connectedAt'] = (new \DateTimeImmutable($age))->format(\DATE_ATOM);
        $settings->set(WebmasterStore::CONNECTION, $stored);
    }
}

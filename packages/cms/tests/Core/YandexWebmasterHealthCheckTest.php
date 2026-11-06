<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\YandexWebmasterHealthCheck;
use Itk\Cms\Tests\Core\Fixture\YandexWebmasterStand;
use Itk\Cms\YandexWebmaster\Model\WebmasterSnapshot;
use Itk\Cms\YandexWebmaster\WebmasterStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** «Яндекс Вебмастер» в «Состоянии сайта»: читает только сохранённое и в Яндекс не ходит. */
final class YandexWebmasterHealthCheckTest extends KernelTestCase
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

    private function check(): HealthResult
    {
        return (new YandexWebmasterHealthCheck($this->webmasterService()))->check();
    }

    private function connect(): void
    {
        $this->yandexKnowsNothing();
        $this->webmasterService()->connect(self::TOKEN);
        $this->calls = [];
    }

    #[Test]
    public function notConnectedIsCalm(): void
    {
        $result = $this->check();

        self::assertSame([HealthResult::OK, 'Не подключён'], [$result->status, $result->message]);
        self::assertSame('Яндекс Вебмастер', (new YandexWebmasterHealthCheck($this->webmasterService()))->name());
    }

    #[Test]
    public function aSiteWithoutAPublicAddressThatWasNeverConnectedIsCalmToo(): void
    {
        $this->siteAt('localhost:8000');

        self::assertSame([HealthResult::OK, 'Не подключён'], [$this->check()->status, $this->check()->message]);
    }

    #[Test]
    public function connectedButTheAddressWentAwayIsAWarning(): void
    {
        $this->connect();
        $this->siteAt('');

        $result = $this->check();

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('Настройки → Сайт', $result->message);
    }

    #[Test]
    public function aRefusedTokenIsAWarning(): void
    {
        $this->connect();
        $this->answer('GET ' . self::HOST_PATH . '/verification', 403, ['error_code' => 'INVALID_OAUTH_TOKEN']);
        $this->webmasterService()->status(true);
        $this->calls = [];

        $result = $this->check();

        self::assertSame([HealthResult::WARN, 'Яндекс не принимает токен. Вставьте новый на экране «Сегодня»'], [$result->status, $result->message]);
    }

    #[Test]
    public function aTokenThatEndsSoonIsAWarningWithTheDate(): void
    {
        $this->connect();
        $this->connectedDaysAgo(170);

        $result = $this->check();

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString((new \DateTimeImmutable('-170 days'))->modify('+180 days')->format('d.m.Y'), $result->message);
    }

    #[Test]
    public function aRunningCheckIsOk(): void
    {
        $this->connect();

        self::assertSame([HealthResult::OK, 'Подключён, Яндекс проверяет права на сайт'], [$this->check()->status, $this->check()->message]);
    }

    #[Test]
    public function aFailedCheckIsAWarningWithTheReason(): void
    {
        $this->connect();
        $this->answer('GET ' . self::HOST_PATH . '/verification', 200, ['verification_uin' => self::UIN, 'verification_state' => 'VERIFICATION_FAILED', 'fail_info' => ['reason' => 'META_TAG_NOT_FOUND']]);
        $this->makeSnapshotStale();
        $this->webmasterService()->status();
        $this->calls = [];

        $result = $this->check();

        self::assertSame([HealthResult::WARN, 'Яндекс не подтвердил права на сайт: META_TAG_NOT_FOUND'], [$result->status, $result->message]);
    }

    #[Test]
    public function aVerifiedSiteIsOkWithTheIndexAndThePagesInSearch(): void
    {
        $this->connect();
        $this->yandexVerified();
        $this->webmasterService()->status(true);
        $this->calls = [];

        $result = $this->check();

        self::assertSame([HealthResult::OK, 'Подключён, права на сайт подтверждены: ИКС 40, страниц в поиске 120'], [$result->status, $result->message]);
    }

    #[Test]
    public function aVerifiedSiteYandexHasNotLoadedYetIsOkWithoutNumbers(): void
    {
        $this->connect();
        $this->yandexVerified();
        $this->answer('GET ' . self::HOST_PATH . '/summary', 404, ['error_code' => 'HOST_NOT_LOADED']);
        $this->webmasterService()->status(true);
        $this->calls = [];

        self::assertSame([HealthResult::OK, 'Подключён, права на сайт подтверждены'], [$this->check()->status, $this->check()->message]);
    }

    #[Test]
    public function theLineNeverCallsYandex(): void
    {
        $this->connect();
        $this->makeSnapshotStale();

        $this->check();

        self::assertSame([], $this->calls);
    }

    private function connectedDaysAgo(int $days): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $stored = $settings->stored(WebmasterStore::CONNECTION);
        self::assertIsArray($stored);
        $stored['connectedAt'] = (new \DateTimeImmutable('-' . $days . ' days'))->format(\DATE_ATOM);
        $settings->set(WebmasterStore::CONNECTION, $stored);
    }

    private function makeSnapshotStale(): void
    {
        $store = self::getContainer()->get(WebmasterStore::class);
        $snapshot = $store->snapshot();
        $store->saveSnapshot(new WebmasterSnapshot($snapshot->verification, $snapshot->summary, $snapshot->queries, $snapshot->updatedAt, new \DateTimeImmutable('-2 days'), $snapshot->errorKey, $snapshot->errorCode, $snapshot->tokenInvalid));
    }
}

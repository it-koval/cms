<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\IndexNowHealthCheck;
use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\Tests\Core\Fixture\IndexNowSettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** «IndexNow» в «Состоянии сайта»: выключено, нет адреса, адрес не публичный, включено с ключом и последней отправкой, ошибка ответа называется. */
final class IndexNowHealthCheckTest extends KernelTestCase
{
    use IndexNowSettings;

    protected function tearDown(): void
    {
        $this->restoreSettings();
        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function check(array $values): HealthResult
    {
        self::bootKernel();
        $this->forgetSubmission();
        foreach ($values as $key => $value) {
            $this->setting($key, $value);
        }

        return self::getContainer()->get(IndexNowHealthCheck::class)->check();
    }

    #[Test]
    public function offIsNotConnectedAndSaysSo(): void
    {
        $result = $this->check(['indexNow' => false, 'domain' => 'health.example.org']);

        self::assertSame([HealthResult::OFF, 'Выключено'], [$result->status, $result->message]);
    }

    #[Test]
    public function withoutAnAddressItAsksForOne(): void
    {
        $result = $this->check(['indexNow' => true, 'domain' => '']);

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('Настройки → Сайт', $result->message);
    }

    #[Test]
    public function anAddressThatIsNotPublicIsCalmlyReported(): void
    {
        $result = $this->check(['indexNow' => true, 'domain' => 'localhost:8000']);

        self::assertSame(HealthResult::OFF, $result->status);
        self::assertStringContainsString('не публичный', $result->message);
    }

    #[Test]
    public function onShowsTheKeyFileAndTheLastSubmission(): void
    {
        $none = $this->check(['indexNow' => true, 'domain' => 'health.example.org']);
        $site = self::getContainer()->get(IndexNowSite::class);
        $file = '/' . $site->key() . '.txt';

        self::assertSame([HealthResult::OK, 'Включено, ключ ' . $file . '. Отправок ещё не было'], [$none->status, $none->message]);

        $site->recordSubmission(4, 202);
        $sent = self::getContainer()->get(IndexNowHealthCheck::class)->check();

        self::assertSame(HealthResult::OK, $sent->status);
        self::assertMatchesRegularExpression('~^Включено, ключ ' . \preg_quote($file, '~') . '\. Последняя отправка: \d\d\.\d\d\.\d{4} \d\d:\d\d, адресов: 4, ответ 202$~u', $sent->message);
    }

    #[Test]
    public function aRefusedSubmissionIsAWarning(): void
    {
        $this->check(['indexNow' => true, 'domain' => 'health.example.org']);
        self::getContainer()->get(IndexNowSite::class)->recordSubmission(1, 403, 'bad key');

        $result = self::getContainer()->get(IndexNowHealthCheck::class)->check();

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('ответ 403', $result->message);
    }

    #[Test]
    public function aFailedRequestShowsItsReason(): void
    {
        $this->check(['indexNow' => true, 'domain' => 'health.example.org']);
        self::getContainer()->get(IndexNowSite::class)->recordSubmission(1, 0, 'connection refused');

        $result = self::getContainer()->get(IndexNowHealthCheck::class)->check();

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('connection refused', $result->message);
    }
}

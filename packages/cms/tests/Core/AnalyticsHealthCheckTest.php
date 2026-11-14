<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Health\AnalyticsHealthCheck;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** «Аналитика и вебмастера» в «Состоянии сайта»: не подключена, подключено столько-то, неверное значение названо. */
final class AnalyticsHealthCheckTest extends WebTestCase
{
    private const KEYS = ['yandexMetrikaId', 'yandexMetrikaWebvisor', 'googleAnalyticsId', 'yandexVerification', 'googleVerification'];

    protected function tearDown(): void
    {
        $c = self::getContainer();
        foreach (self::KEYS as $key) {
            $c->get(SettingsService::class)->set($key, null);
        }
        $c->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-anh-%@example.com')->execute();
        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function check(array $values): HealthResult
    {
        self::bootKernel();
        $c = self::getContainer();
        foreach ($values as $key => $value) {
            $c->get(SettingsService::class)->set($key, $value);
        }

        return $c->get(AnalyticsHealthCheck::class)->check();
    }

    #[Test]
    public function nothingSetIsOffAndSaysSo(): void
    {
        $result = $this->check([]);

        self::assertSame(HealthResult::OFF, $result->status);
        self::assertSame('Не подключена', $result->message);
        self::assertSame(HealthResult::OFF, $this->check(['yandexMetrikaId' => '', 'yandexMetrikaWebvisor' => true])->status, 'a toggle without a counter is nothing');
    }

    #[Test]
    public function whatIsConnectedIsListed(): void
    {
        $result = $this->check(['yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'g-abcd1234', 'yandexVerification' => 'ya-code_Q7z9Kp2m', 'googleVerification' => '<meta name="google-site-verification" content="AbCdEfGh-1234_xyz">']);

        self::assertSame(HealthResult::OK, $result->status);
        self::assertSame('Подключено: Метрика №12345678, GA4 G-ABCD1234, подтверждение Яндекс, подтверждение Google', $result->message);
        self::assertSame('Подключено: GA4 G-ABCD1234', $this->check(['yandexMetrikaId' => null, 'yandexVerification' => null, 'googleVerification' => null])->message);
    }

    #[Test]
    public function everyInvalidValueIsNamed(): void
    {
        $result = $this->check(['yandexMetrikaId' => '12ab', 'googleAnalyticsId' => 'UA-12345-1', 'yandexVerification' => 'short', 'googleVerification' => 'ya-code_Q7z9Kp2m']);

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('Яндекс Метрика: номер счётчика', $result->message);
        self::assertStringContainsString('Google Analytics 4: идентификатор', $result->message);
        self::assertStringContainsString('Яндекс Вебмастер: код подтверждения', $result->message);
        self::assertStringNotContainsString('Google Search Console', $result->message, 'the valid one is not blamed');
    }

    #[Test]
    public function theLineIsOnTheHealthScreen(): void
    {
        $client = static::createClient();
        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-anh-screen@example.com', 'Состояние', 'admin', 'password-for-test'), 'itk_admin');
        $client->request('GET', '/admin');
        $client->disableReboot();
        self::getContainer()->get(SettingsService::class)->set('yandexMetrikaId', 'abc');

        $client->request('GET', '/admin/api/health');

        self::assertResponseIsSuccessful();
        $checks = \array_column(\json_decode((string) $client->getResponse()->getContent(), true)['checks'], null, 'name');
        self::assertSame('warn', $checks['Аналитика и вебмастера']['status']);
        self::assertStringContainsString('Яндекс Метрика: номер счётчика', $checks['Аналитика и вебмастера']['message']);
    }
}

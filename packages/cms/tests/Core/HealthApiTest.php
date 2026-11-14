<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Request as RequestEntity;
use Itk\Cms\Entity\User;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\BrokenHealthCheck;
use Itk\Cms\Tests\Core\Fixture\WarnHealthCheck;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** «Разработчику → Состояние сайта»: GET health, действия, права области dev, пункт меню, проверки расширений. */
final class HealthApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    /** @var list<int> */
    private array $requestIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-health@example.com', 'Тест состояния', 'admin', 'password-for-test'));
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        // a resend changes the error of a request and rings the bell about the delivery: both go away with the request
        foreach ($this->requestIds as $id) {
            $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.dedupeKey = :k OR n.title = :t')->setParameter('k', Notification::TELEGRAM_FAILED . ':' . $id)->setParameter('t', 'Новая заявка №' . $id)->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id = :i')->setParameter('i', $id)->execute();
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.deliveryError LIKE :e')->setParameter('e', '%test-health%')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf];
        $this->client->request($method, '/admin/api/' . $path, [], [], $server, [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[Test]
    public function theReportHasEveryBlock(): void
    {
        $r = $this->api('GET', 'health');

        foreach (['status', 'generatedAt', 'messenger', 'requests', 'schedules', 'disk', 'versions', 'updates', 'checks'] as $key) {
            self::assertArrayHasKey($key, $r, $key);
        }
        self::assertContains($r['status'], ['ok', 'warn', 'fail']);
        self::assertArrayHasKey('queues', $r['messenger']);
        self::assertTrue($r['messenger']['failed']['available'], 'the site has a failure transport');
        self::assertIsArray($r['messenger']['failed']['items']);
        self::assertSame(\PHP_VERSION, $r['versions']['php']);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $r['versions']['symfony']);
        self::assertSame(['uploads', 'var'], \array_column($r['disk'], 'key'));
        foreach ($r['disk'] as $d) {
            self::assertGreaterThan(0, $d['total']);
            self::assertGreaterThanOrEqual(0, $d['usedPct']);
        }
        self::assertIsBool($r['schedules']['available']);
        self::assertIsArray($r['schedules']['items']);
    }

    #[Test]
    public function updatesAreOffUntilASourceIsConfigured(): void
    {
        $u = $this->api('GET', 'health')['updates'];

        self::assertFalse($u['enabled']);
        self::assertFalse($u['hasUpdate']);
        self::assertSame('Пакет пока не опубликован', $u['message']);
        self::assertSame($u['message'], $this->api('POST', 'health/updates/refresh')['updates']['message']);
    }

    #[Test]
    public function theCoreChecksRun(): void
    {
        $checks = \array_column($this->api('GET', 'health')['checks'], null, 'name');

        self::assertSame('ok', $checks['База данных']['status']);
        self::assertArrayHasKey('Папка для загрузок', $checks);
    }

    #[Test]
    public function checksOfExtensionsAreShownAndABrokenOneDoesNotBreakTheScreen(): void
    {
        $ext = self::getContainer()->get(Registry::class);
        $ext->healthChecks(WarnHealthCheck::class, BrokenHealthCheck::class, 'App\\Nothing\\Missing');

        $r = $this->api('GET', 'health');

        $checks = \array_column($r['checks'], null, 'name');
        self::assertSame(['warn', 'Ответ шлюза медленнее обычного'], [$checks['Test платёжный шлюз']['status'], $checks['Test платёжный шлюз']['message']]);
        self::assertSame('fail', $checks['Test сломанная проверка']['status']);
        self::assertStringNotContainsString('AAH-secret_token', $checks['Test сломанная проверка']['message']);
        self::assertSame('Такой проверки нет в коде сайта', $checks['App\\Nothing\\Missing']['message']);
        self::assertSame('fail', $r['status'], 'the worst check decides');
    }

    #[Test]
    public function aRequestThatWasNotDeliveredIsListedWithoutPersonalData(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $req = new RequestEntity('call');
        $req->phone = '+7(900)000-00-09';
        $req->name = 'Тест Тестов';
        $req->delivery = RequestEntity::DELIVERY_FAILED;
        $req->deliveryError = 'test-health: Client error bot123456:AAH-secret_token';
        $em->persist($req);
        $em->flush();
        $this->requestIds[] = (int) $req->id;

        $r = $this->api('GET', 'health');

        self::assertGreaterThanOrEqual(1, $r['requests']['failed']);
        $item = \array_values(\array_filter($r['requests']['items'], static fn (array $i): bool => $i['id'] === $req->id))[0];
        self::assertStringContainsString('bot***', $item['error']);
        self::assertStringNotContainsString('AAH-secret_token', $item['error']);
        self::assertArrayNotHasKey('phone', $item);
        self::assertArrayNotHasKey('name', $item);
        self::assertNotSame('ok', $r['status'], 'an undelivered request is at least a warning');
    }

    #[Test]
    public function resendingARequestReportsTheOutcome(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $req = new RequestEntity('call');
        $req->delivery = RequestEntity::DELIVERY_FAILED;
        $req->deliveryError = 'test-health: старая ошибка';
        $em->persist($req);
        $em->flush();
        $this->requestIds[] = (int) $req->id;

        $r = $this->api('POST', 'health/requests/' . $req->id . '/resend');

        self::assertTrue($r['ok']);
        self::assertContains($r['delivery'], ['sent', 'failed', 'pending']);
        $this->api('POST', 'health/requests/999999999/resend', [], 404);
    }

    #[Test]
    public function anUnknownFailedMessageIsNotFound(): void
    {
        $this->api('POST', 'health/messenger/999999999/retry', [], 404);
        $this->api('POST', 'health/messenger/999999999/delete', [], 404);
    }

    #[Test]
    public function onlyThePeopleWithTheDevAreaSeeItAndItIsInTheMenu(): void
    {
        $nav = $this->api('GET', 'schema')['nav'];
        $labels = [];
        foreach ($nav as $g) {
            foreach ($g['items'] as $it) {
                $labels[$it['key']] = [$g['group'], $it['to']];
            }
        }
        self::assertSame(['Разработчику', '/health'], $labels['health']);
        $areas = \array_column($this->api('GET', 'schema')['permissionAreas'], null, 'key');
        self::assertSame(['view', 'edit'], $areas['dev']['actions']);
        self::assertSame('dev', $areas['dev']['group']);

        $this->signIn(self::getContainer()->get(UserManager::class)->create('test-health-editor@example.com', 'Редактор', 'editor', 'password-for-test'));
        $this->api('GET', 'health', [], 403);
        $this->api('POST', 'health/messenger/1/retry', [], 403);
        $this->api('POST', 'health/messenger/1/delete', [], 403);
        $this->api('POST', 'health/requests/1/resend', [], 403);
        $this->api('POST', 'health/updates/refresh', [], 403);
        foreach ($this->api('GET', 'schema')['nav'] as $g) {
            self::assertNotContains('health', \array_column($g['items'], 'key'), 'the editor has no such menu item');
        }
    }
}

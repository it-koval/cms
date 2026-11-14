<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\User;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotifierInterface;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Колокольчик по HTTP: список, «только непрочитанные», «прочитано», аудитория, вход и CSRF. */
final class NotificationsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // the tests count what a person sees: a note left by any other test must not be in the box
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n')->execute();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.type LIKE :t')->setParameter('t', 'test.%')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function signIn(string $name, string $role): User
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-' . $name . '@example.com', 'Test ' . $name, $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';

        return $user;
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200, bool $withToken = true): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($withToken ? ['HTTP_X_CSRF_TOKEN' => $this->csrf] : []);
        $this->client->request($method, '/admin/api/' . $path, [], [], $server, [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function notify(string $title, ?string $audience = null): void
    {
        self::getContainer()->get(NotifierInterface::class)->notify(new Notification('test.test', $title, 'Подробности', '/requests/1', Notification::WARNING, $audience, ['n' => 1]));
    }

    #[Test]
    public function theBellNeedsALogin(): void
    {
        $this->client->request('GET', '/admin/api/notifications');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aPersonReadsHisNotificationsAndMarksThemRead(): void
    {
        $this->signIn('bell-admin', 'admin');
        $this->notify('Первое');
        $this->notify('Второе');

        $all = $this->api('GET', 'notifications');
        self::assertSame(['Второе', 'Первое'], \array_column($all['items'], 'title'), 'the newest first');
        self::assertSame([2, 2], [$all['total'], $all['unread']]);
        $item = $all['items'][0];
        self::assertSame(['test.test', 'Подробности', '/requests/1', 'warning', false], [$item['type'], $item['body'], $item['link'], $item['level'], $item['read']]);
        self::assertSame(['n' => 1], $item['payload']);
        self::assertArrayHasKey('createdAt', $item);

        $paged = $this->api('GET', 'notifications?limit=1&offset=1');
        self::assertSame(['Первое'], \array_column($paged['items'], 'title'));
        self::assertSame(2, $paged['total']);

        $done = $this->api('POST', 'notifications/read', ['ids' => [$item['id']]]);
        self::assertSame(['ok' => true, 'unread' => 1], $done);
        self::assertSame(['Первое'], \array_column($this->api('GET', 'notifications?unread=1')['items'], 'title'));
        self::assertSame(2, $this->api('GET', 'notifications')['total'], 'read ones stay in the full list');

        $this->api('POST', 'notifications/read', ['ids' => [$item['id']]]); // twice is fine
        self::assertSame(['ok' => true, 'unread' => 0], $this->api('POST', 'notifications/read', ['all' => true]));
        self::assertSame([], $this->api('GET', 'notifications?unread=1')['items']);
        self::assertTrue($this->api('GET', 'notifications')['items'][0]['read']);
    }

    #[Test]
    public function aPersonSeesOnlyWhatIsAddressedToHim(): void
    {
        $this->signIn('bell-author', 'author');
        $this->notify('Всем');
        $this->notify('Заявки', 'requests');
        $this->notify('Пользователи', 'set:users');
        $this->notify('Авторам', 'role:author');

        $list = $this->api('GET', 'notifications');
        self::assertEqualsCanonicalizing(['Всем', 'Авторам'], \array_column($list['items'], 'title'));
        self::assertSame(2, $list['unread']);

        // an id he cannot see is not marked
        $hidden = (int) self::getContainer()->get(EntityManagerInterface::class)->getRepository(\Itk\Cms\Entity\StoredNotification::class)->findOneBy(['title' => 'Пользователи'])->id;
        self::assertSame(2, $this->api('POST', 'notifications/read', ['ids' => [$hidden]])['unread']);
    }

    #[Test]
    public function markingReadNeedsTheCsrfToken(): void
    {
        $this->signIn('bell-csrf', 'admin');
        $this->notify('Без токена');

        $this->api('POST', 'notifications/read', ['all' => true], 403, false);
        self::assertSame(1, $this->api('GET', 'notifications')['unread']);
    }
}

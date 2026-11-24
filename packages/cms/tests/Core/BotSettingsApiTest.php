<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BotSettingsApiTest extends WebTestCase
{
    private const TELEGRAM = '123456789:abcdefghijklmnopqrstuvwxyz_123456789';
    private const MAX = 'private-max-token';
    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        try {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key IN (:keys)')->setParameter('keys', ['itk:telegramBot', 'itk:maxBot'])->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :email')->setParameter('email', 'test-bots-%@example.com')->execute();
            $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key LIKE :key')->setParameter('key', 'test-bots-%')->execute();
        } finally {
            parent::tearDown();
        }
    }

    private function signIn(string $role = 'admin'): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-bots-' . $role . '@example.com', 'Bots test', $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $matches);
        $this->csrf = $matches[1] ?? '';
        self::assertNotSame('', $this->csrf);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/settings/bots', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], \json_encode($body));
        self::assertResponseStatusCodeSame($status);

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[Test]
    public function aMissingTokenIsNotReportedAsAProblemOnTheScreenWhereItIsSetButAnUnreadableOneIs(): void
    {
        $this->signIn();
        $state = $this->api('GET');
        self::assertFalse($state['telegram']['tokenSet']);
        self::assertArrayNotHasKey('problem', $state['telegram'], 'the bots screen itself is where the token is entered');
        self::assertArrayNotHasKey('problem', $state['max']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Setting('itk:maxBot', Setting::ALL, ['token' => 'sealed-with-another-app-secret']));
        $em->flush();
        self::assertArrayHasKey('problem', $this->api('GET')['max']);
    }

    #[Test]
    public function getAndPutReturnStateOnlyAndGenericSettingsAndHistoryDoNotExposeTheRows(): void
    {
        $this->signIn();
        $saved = $this->api('PUT', ['telegram' => ['token' => self::TELEGRAM], 'max' => ['token' => self::MAX]]);
        foreach ([$saved, $this->api('GET')] as $state) {
            foreach (['telegram', 'max'] as $channel) {
                self::assertTrue($state[$channel]['tokenSet']);
                self::assertSame('admin', $state[$channel]['source']);
                self::assertArrayHasKey('ready', $state[$channel]);
                self::assertArrayNotHasKey('token', $state[$channel]);
            }
            self::assertStringNotContainsString(self::TELEGRAM, (string) \json_encode($state));
            self::assertStringNotContainsString(self::MAX, (string) \json_encode($state));
        }
        $this->client->request('GET', '/admin/api/settings');
        self::assertResponseIsSuccessful();
        $text = (string) $this->client->getResponse()->getContent();
        foreach (['itk:telegramBot', 'itk:maxBot', self::TELEGRAM, self::MAX] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->find(Setting::class, ['key' => 'itk:telegramBot', 'locale' => '*']));
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM itk_revision WHERE resource_id IN ('itk:telegramBot', 'itk:maxBot', 'bots')"));
        $this->api('PUT', ['telegram' => ['token' => ''], 'max' => ['token' => '']]);
        self::assertSame(self::TELEGRAM, self::getContainer()->get(BotSettings::class)->token('telegram'));
        $this->api('PUT', ['telegram' => ['clear' => true], 'max' => ['clear' => true]]);
        self::assertNull(self::getContainer()->get(BotSettings::class)->token('telegram'));
        self::assertNull(self::getContainer()->get(BotSettings::class)->token('max'));
    }

    #[Test]
    public function invalidTokensReturn422WithBothFieldErrors(): void
    {
        $this->signIn();
        $bad = $this->api('PUT', ['telegram' => ['token' => 'bad-token'], 'max' => ['token' => 'bad token']], 422);
        self::assertArrayHasKey('telegram', $bad['details']);
        self::assertArrayHasKey('max', $bad['details']);
        self::assertStringNotContainsString('bad-token', (string) \json_encode($bad));
    }

    #[Test]
    public function onlyAnExplicitBotsAreaAllowsViewingAndEditing(): void
    {
        $this->signIn('editor');
        $this->api('GET', [], 403);
        $this->api('PUT', [], 403);
        $role = new Role('test-bots-viewer', 'Bots viewer');
        $role->perms = ['set:bots' => ['view']];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();
        $this->signIn($role->key);
        $this->api('GET');
        $this->api('PUT', ['max' => ['token' => self::MAX]], 403);
    }

    #[Test]
    public function savesRequireCsrf(): void
    {
        $this->signIn();
        $this->csrf = '';
        $this->api('PUT', ['max' => ['token' => self::MAX]], 403);
        self::assertNull(self::getContainer()->get(BotSettings::class)->token('max'));
    }
}

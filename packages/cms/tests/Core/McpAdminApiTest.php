<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\User;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Security\TwoFactor\Totp;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** «Мой профиль → токены для ИИ-ассистентов» (/admin/api/me/tokens) and «Настройки → ИИ-ассистенты» (/admin/api/mcp). */
final class McpAdminApiTest extends WebTestCase
{
    use McpSupport;

    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('REMOTE_ADDR', '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . \random_int(1, 250));
        $this->mcpBoot();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE Itk\Cms\Entity\Role r SET r.require2fa = false')->execute();
        $this->mcpClean();
        parent::tearDown();
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200, bool $csrf = true): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json'] + ($csrf ? ['HTTP_X_CSRF_TOKEN' => $this->csrf] : []), [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function user(string $role): User
    {
        return $this->mcpUser($role);
    }

    /* ---------- the profile ---------- */

    #[Test]
    public function aPersonMakesListsAndRevokesHisKeysAndSeesTheKeyOnce(): void
    {
        $user = $this->user('editor');
        $this->signIn($user);

        $list = $this->api('GET', 'me/tokens');
        self::assertSame([], $list['tokens']);
        self::assertStringEndsWith('/mcp', $list['endpoint']);
        $scopes = \array_column($list['scopes'], null, 'key');
        self::assertTrue($scopes['read']['available'] && $scopes['write']['available'] && $scopes['publish']['available']);
        self::assertTrue($scopes['requests']['available'], 'an editor may read requests');
        self::assertSame('Заявки содержат телефоны клиентов: они уйдут во внешний ИИ-сервис', $scopes['requests']['warning']);
        self::assertFalse($scopes['requests']['default']);
        self::assertTrue($scopes['read']['default']);

        $made = $this->api('POST', 'me/tokens', ['name' => 'Claude на ноутбуке', 'scopes' => ['read', 'write'], 'expires' => 90], 201);
        self::assertMatchesRegularExpression('/^itk_[A-Za-z0-9_-]{43}$/', $made['plain']);
        self::assertSame(['read', 'write'], $made['token']['scopes']);
        self::assertSame('active', $made['token']['state']);
        self::assertNotNull($made['token']['expiresAt']);
        self::assertArrayNotHasKey('plain', $made['token']);
        self::assertStringNotContainsString($made['plain'], (string) \json_encode($made['token']));

        $after = $this->api('GET', 'me/tokens');
        self::assertCount(1, $after['tokens']);
        self::assertStringNotContainsString($made['plain'], (string) $this->client->getResponse()->getContent(), 'the key is shown once');
        self::assertNull($after['tokens'][0]['lastUsedAt']);

        // the key works, and the list shows when
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseIsSuccessful();
        $this->signIn($user);
        self::assertNotNull($this->api('GET', 'me/tokens')['tokens'][0]['lastUsedAt']);

        $rev = $this->api('POST', 'me/tokens/' . $made['token']['id'] . '/revoke');
        self::assertSame('revoked', $rev['state']);
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aKeyNeedsAValidNameScopesAndTerm(): void
    {
        $this->signIn($this->user('author'));
        $this->api('POST', 'me/tokens', ['name' => '', 'scopes' => ['read']], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['read', 'god']], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => []], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['requests']], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['publish']], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['read'], 'expires' => 0], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['read'], 'expires' => 'soon'], 422);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['read'], 'expires' => 100000], 422);
        $ok = $this->api('POST', 'me/tokens', ['name' => 'Только чтение'], 201);
        self::assertSame(['read'], $ok['token']['scopes'], 'read is the default');
        $forever = $this->api('POST', 'me/tokens', ['name' => 'Без срока', 'scopes' => ['read'], 'expires' => null], 201);
        self::assertNull($forever['token']['expiresAt']);
    }

    #[Test]
    public function aKeyWithoutATermLivesNinetyDaysAndOnlyAnExplicitNullMeansForever(): void
    {
        $this->signIn($this->user('editor'));
        $default = $this->api('POST', 'me/tokens', ['name' => 'Без срока в теле', 'scopes' => ['read']], 201);
        self::assertNotNull($default['token']['expiresAt'], 'the API does not hand out a key for ever by default');
        self::assertEqualsWithDelta((new \DateTimeImmutable('+90 days'))->getTimestamp(), (new \DateTimeImmutable($default['token']['expiresAt']))->getTimestamp(), 60);
        $forever = $this->api('POST', 'me/tokens', ['name' => 'Намеренно без срока', 'scopes' => ['read'], 'expires' => null], 201);
        self::assertNull($forever['token']['expiresAt'], 'the form offers «Без срока» and sends null');
        $year = $this->api('POST', 'me/tokens', ['name' => 'Год', 'scopes' => ['read'], 'expires' => 365], 201);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+365 days'))->getTimestamp(), (new \DateTimeImmutable($year['token']['expiresAt']))->getTimestamp(), 60);
    }

    #[Test]
    public function requestsCannotShareAKeyWithWritingOrPublishing(): void
    {
        $this->signIn($this->user('admin'));
        $catalog = \array_column($this->api('GET', 'me/tokens')['scopes'], null, 'key');
        self::assertSame(['write', 'publish'], $catalog['requests']['excludes']);
        self::assertSame(['requests'], $catalog['write']['excludes']);
        self::assertSame(['requests'], $catalog['publish']['excludes']);
        self::assertArrayNotHasKey('excludes', $catalog['read']);

        foreach ([['read', 'requests', 'write'], ['requests', 'publish'], ['read', 'write', 'publish', 'requests']] as $scopes) {
            $r = $this->api('POST', 'me/tokens', ['name' => 'Всё сразу', 'scopes' => $scopes], 422);
            self::assertStringContainsString('Заявки', $r['error']);
            self::assertStringContainsString('Менять черновики', $r['error']);
            self::assertArrayHasKey('scopes', $r['details']);
        }
        $this->api('POST', 'me/tokens', ['name' => 'Только заявки', 'scopes' => ['read', 'requests']], 201);
        $this->api('POST', 'me/tokens', ['name' => 'Только правки', 'scopes' => ['read', 'write', 'publish']], 201);
        self::assertCount(2, $this->api('GET', 'me/tokens')['tokens'], 'the refused ones were not made');
    }

    #[Test]
    public function aKeyIssuedBeforeTheRuleKeepsWorkingWithAllItsScopes(): void
    {
        $made = $this->mcpForcedKey($this->user('admin'), ['read', 'write', 'publish', 'requests']); // as if made before: the rule is checked when a key is made, not when it is used
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
        self::assertResponseIsSuccessful();
        $names = \array_column(\json_decode((string) $this->client->getResponse()->getContent(), true)['result']['tools'], 'name');
        self::assertContains('page_publish', $names);
        self::assertContains('requests_list', $names);
    }

    #[Test]
    public function requestsAreOffOnlyForThosePeopleWhoMayReadThem(): void
    {
        $this->signIn($this->user('requests'));
        $list = $this->api('GET', 'me/tokens');
        $scopes = \array_column($list['scopes'], 'available', 'key');
        self::assertTrue($scopes['requests']);
        self::assertFalse($scopes['write']);
        $made = $this->api('POST', 'me/tokens', ['name' => 'Заявки', 'scopes' => ['read', 'requests']], 201);
        self::assertSame(['read', 'requests'], $made['token']['scopes']);
    }

    #[Test]
    public function everythingIsRefusedWhileMcpIsOff(): void
    {
        $user = $this->user('admin');
        $this->signIn($user);
        $made = $this->api('POST', 'me/tokens', ['name' => 'До выключения', 'scopes' => ['read']], 201);
        self::getContainer()->get(McpSettings::class)->setEnabled(false);
        $this->signIn($user);
        $r = $this->api('GET', 'me/tokens', [], 403);
        self::assertStringContainsString('выключены', $r['error']);
        $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['read']], 403);
        $this->api('POST', 'me/tokens/' . $made['token']['id'] . '/revoke', [], 403);
    }

    #[Test]
    public function aStateChangingCallNeedsTheCsrfToken(): void
    {
        $this->signIn($this->user('admin'));
        $r = $this->api('POST', 'me/tokens', ['name' => 'x', 'scopes' => ['read']], 403, false);
        self::assertSame('csrf', $r['details']['code']);
        $this->api('PUT', 'mcp', ['enabled' => false], 403, false);
        self::assertTrue(self::getContainer()->get(McpSettings::class)->isEnabled());
    }

    #[Test]
    public function aPersonCannotSeeOrRevokeTheKeysOfSomebodyElse(): void
    {
        $other = $this->mcpKey($this->user('admin'), ['read'], 30, 'TestMcp чужой');
        $me = $this->user('editor');
        $this->signIn($me);
        self::assertSame([], $this->api('GET', 'me/tokens')['tokens']);
        $this->api('POST', 'me/tokens/' . $other['token']->id . '/revoke', [], 404);
        $this->api('POST', 'me/tokens/no-such-id/revoke', [], 404);
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $other['plain']], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aSessionThatHasNotPassedTheSecondFactorMakesNoKeys(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factor = self::getContainer()->get(TwoFactorService::class);
        $user = $this->user('admin');
        $secret = $factor->startSetup($user, 'Тест')['secret'];
        $factor->enable($user, Totp::code($secret, \time()));
        $em->clear();
        $this->client->loginUser($em->find(User::class, $user->id), 'itk_admin');

        $this->client->request('GET', '/admin/api/me/tokens');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('2fa_code', \json_decode((string) $this->client->getResponse()->getContent(), true)['details']['code']);
        $this->client->request('POST', '/admin/api/me/tokens', [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"x","scopes":["read"]}');
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
        self::assertSame(0, (int) $em->createQuery('SELECT COUNT(t.id) FROM Itk\Cms\Entity\ApiToken t WHERE IDENTITY(t.user) = :u')->setParameter('u', $user->id)->getSingleScalarResult());
    }

    #[Test]
    public function aRoleThatNeedsTwoStepSignInSetsItUpBeforeMakingKeys(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE Itk\Cms\Entity\Role r SET r.require2fa = true WHERE r.key = :k')->setParameter('k', 'editor')->execute();
        $user = $this->user('editor');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('POST', '/admin/api/me/tokens', [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"x","scopes":["read"]}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('2fa_enrol', \json_decode((string) $this->client->getResponse()->getContent(), true)['details']['code']);
    }

    /* ---------- the settings panel ---------- */

    #[Test]
    public function aMistakeInAToolOfTheSiteIsShownOnThePanelInsteadOfBreakingIt(): void
    {
        $this->signIn($this->user('admin'));
        $extensions = new \Itk\Cms\Extension\Registry();
        $extensions->mcpTools(Fixture\SilentMcpTool::class);
        self::getContainer()->set(\Itk\Cms\Mcp\McpToolRegistry::class, new \Itk\Cms\Mcp\McpToolRegistry([], $extensions));

        $panel = $this->api('GET', 'mcp');
        self::assertSame([], $panel['tools']);
        self::assertStringContainsString(Fixture\SilentMcpTool::class, (string) $panel['toolsError']);
        self::assertArrayHasKey('tokens', $panel, 'the keys can still be seen and revoked');
    }

    #[Test]
    public function onlyAdministratorsOpenThePanelAndFlipTheSwitch(): void
    {
        $key = $this->mcpKey($this->user('editor'), ['read'], 30, 'TestMcp редактор');
        $this->signIn($this->user('editor'));
        $this->api('GET', 'mcp', [], 403);
        $this->api('PUT', 'mcp', ['enabled' => false], 403);
        $this->api('POST', 'mcp/tokens/' . $key['token']->id . '/revoke', [], 403);
        $me = $this->api('GET', 'me');
        self::assertArrayNotHasKey('set:mcp', (array) $me['perms']);

        $this->signIn($this->user('admin'));
        $panel = $this->api('GET', 'mcp');
        self::assertTrue($panel['enabled']);
        self::assertNull($panel['toolsError']);
        self::assertStringEndsWith('/mcp', $panel['endpoint']);
        self::assertSame('bin/console itk:mcp', $panel['stdio']);
        self::assertContains('page_publish', \array_column($panel['tools'], 'name'));
        $mine = \array_values(\array_filter($panel['tokens'], static fn (array $t): bool => $t['id'] === $key['token']->id));
        self::assertCount(1, $mine);
        self::assertStringContainsString('editor', $mine[0]['owner']['email']);
        self::assertStringNotContainsString($key['plain'], (string) $this->client->getResponse()->getContent());
        $schema = $this->api('GET', 'schema');
        self::assertContains('set:mcp', \array_column($schema['permissionAreas'], 'key'));
        self::assertSame(['view', 'edit'], \array_column($schema['permissionAreas'], 'actions', 'key')['set:mcp']);
        self::assertArrayHasKey('set:mcp', (array) $this->api('GET', 'me')['perms']);
    }

    #[Test]
    public function theSwitchTurnsTheEndpointOnAndOffAndAnAdministratorRevokesAnyKey(): void
    {
        $key = $this->mcpKey($this->user('editor'), ['read'], 30, 'TestMcp чей-то');
        $this->signIn($this->user('admin'));

        $off = $this->api('PUT', 'mcp', ['enabled' => false]);
        self::assertFalse($off['enabled']);
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key['plain']], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseStatusCodeSame(404);
        $this->api('PUT', 'mcp', ['enabled' => 'yes'], 422);
        $this->api('PUT', 'mcp', [], 422);

        self::assertTrue($this->api('PUT', 'mcp', ['enabled' => true])['enabled']);
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key['plain']], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseIsSuccessful();

        $this->signIn($this->mcpUser('admin', 'two'));
        $panel = $this->api('POST', 'mcp/tokens/' . $key['token']->id . '/revoke');
        $row = \array_values(\array_filter($panel['tokens'], static fn (array $t): bool => $t['id'] === $key['token']->id))[0];
        self::assertSame('revoked', $row['state']);
        $this->api('POST', 'mcp/tokens/nothing/revoke', [], 404);
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key['plain']], '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseStatusCodeSame(401);
        self::assertSame([McpScopes::READ], $row['scopes']);
    }

    #[Test]
    public function theHistoryApiNamesTheAssistant(): void
    {
        $admin = $this->user('admin');
        $key = $this->mcpKey($admin, ['read', 'write'], 30, 'Claude Desktop');
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $key['plain']], (string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'page_create', 'arguments' => ['title' => 'TestMcp History API', 'url' => '/test-mcp-history-api']]]));
        $result = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = \json_decode($result['result']['content'][0]['text'], true)['page']['id'];

        $this->signIn($admin);
        $h = $this->api('GET', 'history/page/' . $id);
        self::assertSame('mcp', $h['items'][0]['via']);
        self::assertSame('Claude Desktop', $h['items'][0]['viaName']);
        self::assertSame($admin->name, $h['items'][0]['userName']);
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Controller\Site\McpController;
use Itk\Cms\Entity\User;
use Itk\Cms\Mcp\ApiTokenAuthenticator;
use Itk\Cms\Mcp\ApiTokenService;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpSettings;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\McpSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/** POST /mcp: the keys (none, wrong, expired, revoked, blocked owner), the off switch, the doors (method, Origin, type, size), limits, and the protocol itself. */
final class McpEndpointTest extends WebTestCase
{
    use McpSupport;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // every test comes "from" its own address: the limit of wrong keys is kept in the cache between runs
        $this->client->setServerParameter('REMOTE_ADDR', '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . \random_int(1, 250));
        $this->mcpBoot();
    }

    protected function tearDown(): void
    {
        $this->mcpClean();
        parent::tearDown();
    }

    /**
     * @param array<array-key, mixed>|string $body
     * @param array<string, string> $headers
     *
     * @return array<array-key, mixed>
     */
    private function rpc(?string $plain, array|string $body, array $headers = [], string $path = '/mcp'): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + $headers;
        if (null !== $plain) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
        }
        $this->client->request('POST', $path, [], [], $server, \is_string($body) ? $body : (string) \json_encode($body));

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function ping(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
    }

    /* ---------- keys ---------- */

    #[Test]
    public function withoutAKeyTheAnswerIs401AndAChallenge(): void
    {
        $r = $this->rpc(null, $this->ping());
        self::assertSame(401, $this->code());
        self::assertStringContainsString('Bearer', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', $r['error']);
    }

    #[Test]
    public function aWrongKeyIs401(): void
    {
        foreach (['nope', 'itk_short', 'itk_' . \str_repeat('a', 43), 'Bearer'] as $plain) {
            $this->rpc($plain, $this->ping());
            self::assertSame(401, $this->code(), $plain);
        }
        $user = $this->mcpUser();
        $made = $this->mcpKey($user);
        self::assertSame(1, $this->rpc($made['plain'], $this->ping())['id'], 'the real key works');
        $this->rpc(\substr($made['plain'], 0, -1) . ('A' === \substr($made['plain'], -1) ? 'B' : 'A'), $this->ping());
        self::assertSame(401, $this->code(), 'one sign off');
    }

    #[Test]
    public function theKeyIsStoredAsAHashAndLooksLikeAKey(): void
    {
        $made = $this->mcpKey($this->mcpUser(), [McpScopes::READ], null, 'TestMcp shape');
        self::assertMatchesRegularExpression('/^itk_[A-Za-z0-9_-]{43}$/', $made['plain']);
        self::assertSame(\hash('sha256', $made['plain']), $made['token']->tokenHash);
        self::assertStringNotContainsString($made['plain'], (string) \json_encode(self::getContainer()->get(ApiTokenService::class)->present($made['token'])));
        self::assertSame(\substr($made['plain'], -4), $made['token']->hint);
        self::assertNull($made['token']->expiresAt, 'no end');
    }

    #[Test]
    public function anExpiredOrRevokedKeyIs401(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->mcpUser();
        $old = $this->mcpKey($user);
        $old['token']->expiresAt = new \DateTimeImmutable('-1 minute');
        $em->flush();
        self::assertSame(401, $this->rpcStatus($old['plain']), 'the very first call: expired');
        $this->rpc($old['plain'], $this->ping());
        self::assertSame(401, $this->code());
        self::assertStringContainsString('expired', (string) $this->client->getResponse()->getContent());

        $gone = $this->mcpKey($user);
        self::assertSame(200, $this->rpcStatus($gone['plain']));
        $service = self::getContainer()->get(ApiTokenService::class);
        $service->revoke($service->find($gone['token']->id));
        self::assertSame(401, $this->rpcStatus($gone['plain']));
        self::assertStringContainsString('revoked', (string) $this->client->getResponse()->getContent());
    }

    /** A request resets the entity manager of the test kernel: change the person in the database, not the old object. */
    private function setUserStatus(User $user, string $status): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE Itk\Cms\Entity\User u SET u.status = :s WHERE u.id = :id')->setParameter('s', $status)->setParameter('id', $user->id)->execute();
        $em->clear();
    }

    private function rpcStatus(string $plain): int
    {
        $this->rpc($plain, $this->ping());

        return $this->code();
    }

    #[Test]
    public function aBlockedOwnerStopsEveryKeyAndUnblockingBringsThemBack(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->mcpUser();
        $a = $this->mcpKey($user);
        $b = $this->mcpKey($user, [McpScopes::READ, McpScopes::WRITE]);
        self::assertSame(200, $this->rpcStatus($a['plain']));

        $this->setUserStatus($user, User::BLOCKED);
        self::assertSame(403, $this->rpcStatus($a['plain']));
        self::assertSame(403, $this->rpcStatus($b['plain']));

        $this->setUserStatus($user, User::ACTIVE);
        self::assertSame(200, $this->rpcStatus($a['plain']));
    }

    #[Test]
    public function aNewPasswordOfTheOwnerDoesNotStopHisKeys(): void
    {
        $user = $this->mcpUser();
        $made = $this->mcpKey($user);
        self::getContainer()->get(UserManager::class)->setPassword($user, 'another-password-1');
        self::assertSame(200, $this->rpcStatus($made['plain']), 'documented: revoke the keys to stop them');
    }

    #[Test]
    public function deletingTheOwnerDeletesHisKeys(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->mcpUser();
        $made = $this->mcpKey($user);
        $id = $made['token']->id;
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.id = :id')->setParameter('id', $user->id)->execute();
        $em->clear();
        self::assertNull($em->find(\Itk\Cms\Entity\ApiToken::class, $id));
        self::assertSame(401, $this->rpcStatus($made['plain']));
    }

    #[Test]
    public function aKeyOfARoleThatNeedsTwoStepSignInIsRefusedUntilItIsSetUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->mcpUser('editor');
        $made = $this->mcpKey($user);
        self::assertSame(200, $this->rpcStatus($made['plain']));
        $set = static function (bool $on) use ($em): void {
            $em->createQuery('UPDATE Itk\Cms\Entity\Role r SET r.require2fa = :on WHERE r.key = :k')->setParameter('on', $on)->setParameter('k', 'editor')->execute();
            $em->clear();
        };
        $set(true);
        try {
            self::assertSame(403, $this->rpcStatus($made['plain']));
            self::assertStringContainsString('two-step', (string) $this->client->getResponse()->getContent());
        } finally {
            $set(false);
        }
    }

    /* ---------- the doors ---------- */

    #[Test]
    public function whileMcpIsOffTheAddressDoesNotExist(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        self::getContainer()->get(McpSettings::class)->setEnabled(false);
        $this->rpc($made['plain'], $this->ping());
        self::assertSame(404, $this->code());
        $this->rpc(null, $this->ping());
        self::assertSame(404, $this->code(), 'not even a challenge');
        $this->client->request('GET', '/mcp');
        self::assertSame(404, $this->code());
        self::getContainer()->get(McpSettings::class)->setEnabled(true);
        self::assertSame(200, $this->rpcStatus($made['plain']));
    }

    /** The same address written with percent-encoding: the router and the firewall read it as /mcp, so every door must. */
    private const ENCODED = ['/m%63p', '/%6dcp', '/%6D%63%70'];

    #[Test]
    public function anEncodedAddressDoesNotWalkAroundTheOffSwitch(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        self::getContainer()->get(McpSettings::class)->setEnabled(false);
        foreach (self::ENCODED as $path) {
            $r = $this->rpc($made['plain'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [], $path);
            self::assertSame(404, $this->code(), $path);
            self::assertSame('not_found', $r['error'], $path);
            $this->rpc(null, $this->ping(), [], $path);
            self::assertSame(404, $this->code(), $path . ': not even a challenge');
            $this->client->request('GET', $path);
            self::assertSame(404, $this->code(), $path . ' GET');
        }
        self::getContainer()->get(McpSettings::class)->setEnabled(true);
        foreach (self::ENCODED as $path) {
            $this->rpc($made['plain'], $this->ping(), [], $path);
            self::assertSame(200, $this->code(), $path . ' works as /mcp when MCP is on');
        }
    }

    #[Test]
    public function theControllerAndTheAuthenticatorCheckTheSwitchThemselves(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $request = Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], (string) \json_encode($this->ping()));
        $authenticator = self::getContainer()->get(ApiTokenAuthenticator::class);
        $controller = self::getContainer()->get(McpController::class);
        self::getContainer()->get(McpSettings::class)->setEnabled(false);

        try {
            $authenticator->authenticate($request);
            self::fail('a key does not open a door that is shut');
        } catch (CustomUserMessageAuthenticationException $e) {
            self::assertSame(404, $e->getCode());
        }
        $request->attributes->set(ApiTokenAuthenticator::ATTRIBUTE, $made['token']->id);
        $response = $controller($request);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('result', (string) $response->getContent());

        self::getContainer()->get(McpSettings::class)->setEnabled(true);
        self::assertNotNull($authenticator->authenticate($request));
        self::assertSame(200, $controller($request)->getStatusCode());
    }

    /** The site did not add the firewall `itk_mcp` to security.yaml: nobody authenticated the request. That is the site's mistake, not a wrong key, and it is said so. */
    #[Test]
    public function withoutTheFirewallTheControllerAnswersAnInternalErrorAndLogsTheHintInsteadOfBlamingTheKey(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{0: string, 1: string}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message];
            }
        };
        $c = self::getContainer();
        $controller = new McpController($c->get(\Itk\Cms\Mcp\McpServer::class), $c->get(ApiTokenService::class), $c->get(\Itk\Cms\Mcp\McpContextFactory::class), $c->get(McpSettings::class), $c->get('limiter.itk_mcp_token'), 8, $logger);
        $request = Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], (string) \json_encode($this->ping()));

        $response = $controller($request); // no attribute _itk_api_token: the firewall did not run
        $body = (array) \json_decode((string) $response->getContent(), true);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('mcp_firewall_not_configured', $body['error']);
        self::assertStringContainsString('security.yaml', $body['error_description']);
        self::assertStringNotContainsString('not valid', $body['error_description']);
        self::assertSame('error', $logger->lines[0][0]);
        self::assertStringContainsString('security.yaml', $logger->lines[0][1]);
        self::assertStringNotContainsString($made['plain'], (string) \json_encode($logger->lines));

        // the firewall ran and named a key that is gone: that one is a wrong key
        $request->attributes->set(ApiTokenAuthenticator::ATTRIBUTE, 'no-such-key-id');
        $gone = $controller($request);
        self::assertSame(401, $gone->getStatusCode());
        self::assertSame('unauthorized', ((array) \json_decode((string) $gone->getContent(), true))['error']);
        self::assertCount(1, $logger->lines, 'a wrong key is not a misconfiguration');
    }

    /** @return array{0: object, 1: \Itk\Cms\Mcp\ApiTokenAuthenticator, 2: McpController} a logger that keeps its lines, the authenticator and the controller that write to it */
    private function audited(): array
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message, $context];
            }
        };
        $c = self::getContainer();
        $authenticator = new ApiTokenAuthenticator($c->get(ApiTokenService::class), $c->get(McpSettings::class), $c->get('limiter.itk_mcp_failed_auth'), $logger);
        $controller = new McpController($c->get(\Itk\Cms\Mcp\McpServer::class), $c->get(ApiTokenService::class), $c->get(\Itk\Cms\Mcp\McpContextFactory::class), $c->get(McpSettings::class), $c->get('limiter.itk_mcp_token'), 8, $logger);

        return [$logger, $authenticator, $controller];
    }

    private function bearer(?string $plain): Request
    {
        return Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . \random_int(1, 250)] + (null === $plain ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]), (string) \json_encode($this->ping()));
    }

    #[Test]
    public function aFailedSignInIsInTheAuditLogWithTheReasonAndNeverWithTheKey(): void
    {
        [$logger, $authenticator] = $this->audited();
        $user = $this->mcpUser();
        $revoked = $this->mcpKey($user);
        $service = self::getContainer()->get(ApiTokenService::class);
        $service->revoke($service->find($revoked['token']->id));
        $wrong = 'itk_' . \bin2hex(\random_bytes(22));

        foreach ([null, $wrong, $revoked['plain']] as $plain) {
            try {
                $authenticator->authenticate($this->bearer($plain));
                self::fail('refused');
            } catch (CustomUserMessageAuthenticationException) {
            }
        }

        self::assertCount(3, $logger->lines);
        foreach ($logger->lines as $line) {
            self::assertSame(['warning', 'MCP sign-in refused: {reason}'], [$line[0], $line[1]]);
            self::assertMatchesRegularExpression('/^10\.\d+\.\d+\.\d+$/', $line[2]['ip']);
        }
        self::assertSame(['missing', 'invalid', 'revoked'], \array_column(\array_column($logger->lines, 2), 'reason'));
        self::assertNull($logger->lines[1][2]['token'], 'an unknown key has no id');
        self::assertSame([$revoked['token']->id, $user->id], [$logger->lines[2][2]['token'], $logger->lines[2][2]['user']], 'a key that is known says whose it is');
        self::assertStringNotContainsString($wrong, (string) \json_encode($logger->lines));
        self::assertStringNotContainsString($revoked['plain'], (string) \json_encode($logger->lines));

        // a switched-off server is a closed door, not news
        self::getContainer()->get(McpSettings::class)->setEnabled(false);
        try {
            $authenticator->authenticate($this->bearer($wrong));
        } catch (CustomUserMessageAuthenticationException) {
        }
        self::assertCount(3, $logger->lines);
    }

    #[Test]
    public function tooManyWrongKeysAndRefusedCallsOfAGoodKeyAreLogged(): void
    {
        [$logger, $authenticator, $controller] = $this->audited();
        $request = $this->bearer('itk_' . \bin2hex(\random_bytes(22)));
        for ($i = 0; $i < 21; ++$i) {
            try {
                $authenticator->authenticate($request);
            } catch (CustomUserMessageAuthenticationException) {
            }
        }
        self::assertSame('too_many_wrong_keys', \end($logger->lines)[2]['reason']);

        $logger->lines = [];
        $made = $this->mcpKey($this->mcpUser());
        $good = $this->bearer($made['plain']);
        $good->attributes->set(ApiTokenAuthenticator::ATTRIBUTE, $made['token']->id);
        $good->headers->set('MCP-Protocol-Version', '1999-01-01');
        self::assertSame(400, $controller($good)->getStatusCode());
        $good->headers->remove('MCP-Protocol-Version');
        $last = 0;
        for ($i = 0; $i < 130; ++$i) {
            $last = $controller($good)->getStatusCode();
            if (429 === $last) {
                break;
            }
        }
        self::assertSame(429, $last);
        /** @var list<array{string, string, array<string, mixed>}> $lines */
        $lines = $logger->lines;
        $reasons = \array_column(\array_column($lines, 2), 'reason');
        self::assertSame(['unsupported_protocol_version', 'too_many_calls'], $reasons);
        foreach ($lines as $line) {
            self::assertSame(['warning', 'MCP call refused: {reason}'], [$line[0], $line[1]]);
            self::assertSame($made['token']->id, $line[2]['token']);
        }
    }

    #[Test]
    public function anEncodedAddressKeepsEveryOtherDoorShut(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        foreach (self::ENCODED as $path) {
            // a page of another site: the same refusal as at /mcp
            $mcp = $this->rpc($made['plain'], $this->ping(), ['HTTP_ORIGIN' => 'https://evil.example']);
            $codeOfMcp = $this->code();
            $r = $this->rpc($made['plain'], $this->ping(), ['HTTP_ORIGIN' => 'https://evil.example'], $path);
            self::assertSame([403, 'forbidden_origin'], [$this->code(), $r['error']], $path);
            self::assertSame([$codeOfMcp, $mcp], [$this->code(), $r], $path . ' is refused as /mcp is');
            // the method
            $this->client->request('GET', $path);
            self::assertSame(405, $this->code(), $path);
            // the type and the size of the body
            $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], '{}');
            self::assertSame(415, $this->code(), $path);
            $this->rpc($made['plain'], $this->ping(), ['CONTENT_LENGTH' => (string) (100 * 1024 * 1024)], $path);
            self::assertSame(413, $this->code(), $path);
        }
    }

    #[Test]
    public function onlyPostIsAccepted(): void
    {
        foreach (['GET', 'DELETE', 'PUT', 'OPTIONS', 'PATCH'] as $method) {
            $this->client->request($method, '/mcp');
            self::assertSame(405, $this->code(), $method);
            self::assertSame('POST', $this->client->getResponse()->headers->get('Allow'));
        }
    }

    #[Test]
    public function aBrowserPageOfAnotherSiteIsRefused(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        foreach (['https://evil.example', 'http://localhost.evil.example', 'null', 'http://192.168.0.1:8088', 'ftp://localhost'] as $origin) {
            $this->rpc($made['plain'], $this->ping(), ['HTTP_ORIGIN' => $origin]);
            self::assertSame(403, $this->code(), $origin);
        }
        // no Origin = a program; localhost = the development site itself (kernel.debug)
        self::assertSame(200, $this->rpcStatus($made['plain']));
        $this->rpc($made['plain'], $this->ping(), ['HTTP_ORIGIN' => 'http://localhost:8088']);
        self::assertSame(200, $this->code());
    }

    #[Test]
    public function theBodyMustBeJsonAndNotHuge(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']], '{}');
        self::assertSame(415, $this->code());
        $this->rpc($made['plain'], $this->ping(), ['CONTENT_LENGTH' => (string) (100 * 1024 * 1024)]);
        self::assertSame(413, $this->code());
    }

    #[Test]
    public function noCookieIsSentAndACookieOpensNothing(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $this->rpc($made['plain'], $this->ping());
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'no session cookie');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // a person signed in to the admin with a session is not a key
        $admin = $this->mcpUser();
        $this->client->loginUser($admin, 'itk_admin');
        $this->rpc(null, $this->ping());
        self::assertSame(401, $this->code());
    }

    #[Test]
    public function aKeyOfTheAdminDoesNotOpenTheAdminApiAndTheOtherWayRound(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $this->client->request('GET', '/admin/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $made['plain']]);
        self::assertSame(401, $this->code());
    }

    #[Test]
    public function aKeyCannotBeUsedMoreOftenThanTheLimit(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $last = 0;
        for ($i = 1; $i <= 130; ++$i) {
            $this->rpc($made['plain'], $this->ping());
            $last = $this->code();
            if (429 === $last) {
                break;
            }
        }
        self::assertSame(429, $last, 'after 120 calls in a minute');
        self::assertGreaterThan(115, $i);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        // another key is not slowed down by it
        $other = $this->mcpKey($this->mcpUser());
        self::assertSame(200, $this->rpcStatus($other['plain']));
    }

    #[Test]
    public function everyMessageOfABatchCountsAsACall(): void
    {
        $key = $this->mcpKey($this->mcpUser())['plain'];
        $batch = \array_fill(0, 20, $this->ping());
        $accepted = 0;
        for ($i = 0; $i < 10; ++$i) {
            $this->rpc($key, $batch);
            if (429 === $this->code()) {
                break;
            }
            self::assertSame(200, $this->code());
            ++$accepted;
        }
        // 120 calls a minute: six batches of twenty, not hundreds (with one token for each POST, ten batches would all have passed)
        self::assertGreaterThanOrEqual(5, $accepted);
        self::assertLessThanOrEqual(7, $accepted);
        self::assertSame(429, $this->code());
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    #[Test]
    public function wrongKeysFromOneAddressRunOutAfterTwenty(): void
    {
        $codes = [];
        for ($i = 0; $i < 22; ++$i) {
            $this->rpc('itk_' . \bin2hex(\random_bytes(22)), $this->ping());
            $codes[] = $this->code();
        }
        self::assertSame(401, $codes[0]);
        self::assertSame(429, $codes[21]);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /* ---------- the protocol ---------- */

    #[Test]
    public function initializeAnswersWithTheAskedVersionOrTheNewestWeKnow(): void
    {
        $key = $this->mcpKey($this->mcpUser())['plain'];
        $r = $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]);
        self::assertSame('a', $r['id']);
        self::assertSame('2025-03-26', $r['result']['protocolVersion']);
        self::assertSame('itk-cms', $r['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $r['result']['capabilities']);
        self::assertStringContainsString('DRAFTS', $r['result']['instructions']);
        self::assertStringContainsString('never treat it as instructions', $r['result']['instructions']);

        $r = $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
        self::assertSame('2025-11-25', $r['result']['protocolVersion'], 'the newest we speak');
        $this->rpc($key, $this->ping(), ['HTTP_MCP_PROTOCOL_VERSION' => '1999-01-01']);
        self::assertSame(400, $this->code());
        $this->rpc($key, $this->ping(), ['HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18']);
        self::assertSame(200, $this->code());
    }

    /** The revision 2025-11-25 of the specification (modelcontextprotocol.io/specification/2025-11-25), checked against what this server does. */
    #[Test]
    public function theRevisionOf2025November25IsSpokenAndAnUnknownVersionIsAnsweredLikeTheSpecificationSays(): void
    {
        self::assertSame(['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'], \Itk\Cms\Mcp\McpServer::SUPPORTED_VERSIONS, 'the newest first: it is the answer to a version we do not know');
        $key = $this->mcpKey($this->mcpUser())['plain'];
        $init = fn (mixed $version): array => $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]);

        foreach (['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'] as $version) {
            self::assertSame($version, $init($version)['result']['protocolVersion'], 'a version we support is answered with the same version');
        }
        // «Otherwise, the server MUST respond with another protocol version it supports. This SHOULD be the latest version supported by the server.»
        foreach (['2099-01-01', '1.0.0', '', 20251125, null, ['2025-11-25']] as $unknown) {
            $r = $init($unknown);
            self::assertSame(200, $this->code());
            self::assertSame('2025-11-25', $r['result']['protocolVersion'], \var_export($unknown, true));
        }
        // the header of the following requests: a version we know is accepted, an unknown or broken one is «400 Bad Request»; without the header the request is accepted
        foreach (['2025-11-25', '2025-03-26'] as $version) {
            $this->rpc($key, $this->ping(), ['HTTP_MCP_PROTOCOL_VERSION' => $version]);
            self::assertSame(200, $this->code(), $version);
        }
        foreach (['2099-01-01', 'latest', '2025-11-26', ''] as $bad) {
            $r = $this->rpc($key, $this->ping(), ['HTTP_MCP_PROTOCOL_VERSION' => $bad]);
            self::assertSame(400, $this->code(), \var_export($bad, true));
            self::assertSame('unsupported_protocol_version', $r['error']);
            self::assertStringContainsString('2025-11-25', $r['error_description'], 'it says which versions there are');
        }
        $this->rpc($key, $this->ping());
        self::assertSame(200, $this->code());
        // a bad Origin stays «403 Forbidden» in this revision too
        $this->rpc($key, $this->ping(), ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25']);
        self::assertSame(403, $this->code());
    }

    #[Test]
    public function pingNotificationsAndBatches(): void
    {
        $key = $this->mcpKey($this->mcpUser())['plain'];
        self::assertEquals(new \stdClass(), (object) $this->rpc($key, $this->ping())['result']);
        $this->rpc($key, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertSame(202, $this->code());
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $r = $this->rpc($key, [$this->ping(), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'nope']]);
        self::assertCount(2, $r);
        self::assertSame(-32601, $r[1]['error']['code']);
        $this->rpc($key, [['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]);
        self::assertSame(202, $this->code());
    }

    #[Test]
    public function brokenMessagesGetTheRightJsonRpcErrors(): void
    {
        $key = $this->mcpKey($this->mcpUser())['plain'];
        self::assertSame(-32700, $this->rpc($key, '{broken')['error']['code']);
        self::assertSame(-32600, $this->rpc($key, '{}')['error']['code']);
        self::assertSame(-32600, $this->rpc($key, '"text"')['error']['code']);
        self::assertSame(-32600, $this->rpc($key, ['id' => 1, 'method' => 'ping'])['error']['code'], 'jsonrpc: 2.0 is required');
        self::assertSame(-32600, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1])['error']['code'], 'no method');
        self::assertSame(-32600, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => ['x'], 'method' => 'ping'])['error']['code']);
        self::assertSame(-32601, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'prompts/list'])['error']['code']);
        self::assertSame(-32602, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'no_such_tool']])['error']['code']);
        self::assertSame(-32602, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []])['error']['code']);
        self::assertSame(-32602, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'x'])['error']['code']);
        $r = $this->rpc($key, \array_fill(0, 21, $this->ping()));
        self::assertSame(-32600, $r['error']['code'], 'a batch of 21');
        self::assertSame(200, $this->code(), 'protocol errors are answers, not HTTP failures');
    }

    #[Test]
    public function theToolsOfAKeyFollowItsScopes(): void
    {
        $call = function (array $scopes, string $role = 'admin'): array {
            $made = $this->mcpForcedKey($this->mcpUser($role), $scopes);
            $r = $this->rpc($made['plain'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

            return \array_column($r['result']['tools'], 'name');
        };
        $read = $call([McpScopes::READ]);
        self::assertContains('site_schema', $read);
        self::assertContains('page_get', $read);
        self::assertNotContains('page_create', $read);
        self::assertNotContains('page_publish', $read);
        self::assertNotContains('requests_list', $read);

        $write = $call([McpScopes::WRITE]);
        self::assertContains('page_create', $write);
        self::assertContains('media_upload', $write);
        self::assertContains('site_schema', $write, 'write brings read');
        self::assertNotContains('page_publish', $write);

        $publish = $call([McpScopes::PUBLISH]);
        self::assertContains('page_publish', $publish);
        self::assertContains('collection_item_publish', $publish);
        self::assertContains('page_create', $publish);
        self::assertNotContains('request_get', $publish);

        $requests = $call([McpScopes::READ, McpScopes::REQUESTS]);
        self::assertContains('requests_list', $requests);
        self::assertContains('request_update', $requests);
        self::assertNotContains('page_create', $requests);

        $noRight = $call([McpScopes::READ, McpScopes::REQUESTS], 'author');
        self::assertNotContains('requests_list', $noRight, 'the scope alone is not enough: the person has no right to requests');
        self::assertContains('pages_list', $noRight);
    }

    #[Test]
    public function aToolOutsideTheScopeCannotBeCalledEvenByName(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        $r = $this->rpc($made['plain'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'page_create', 'arguments' => ['title' => 'TestMcp x', 'url' => '/test-mcp-x']]]);
        self::assertSame(-32602, $r['error']['code']);
        $count = (int) self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne("SELECT COUNT(*) FROM itk_page_translation WHERE draft ->> 'title' LIKE 'TestMcp%'");
        self::assertSame(0, $count);
    }

    #[Test]
    public function theSchemaIsAlsoAResource(): void
    {
        $key = $this->mcpKey($this->mcpUser())['plain'];
        $list = $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list']);
        self::assertSame('itk://schema', $list['result']['resources'][0]['uri']);
        $read = $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'itk://schema']]);
        self::assertArrayHasKey('templates', (array) \json_decode($read['result']['contents'][0]['text'], true));
        self::assertSame(-32002, $this->rpc($key, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => ['uri' => 'itk://other']])['error']['code']);
    }

    #[Test]
    public function aCallMarksTheKeyAsUsed(): void
    {
        $made = $this->mcpKey($this->mcpUser());
        self::assertNull($made['token']->lastUsedAt);
        $this->rpc($made['plain'], $this->ping());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNotNull($em->find(\Itk\Cms\Entity\ApiToken::class, $made['token']->id)->lastUsedAt);
    }
}

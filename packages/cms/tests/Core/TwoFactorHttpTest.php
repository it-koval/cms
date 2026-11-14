<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\TwoFactor\Totp;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Двухэтапный вход по HTTP: страница кода, закрытая админка до кода, обязательная настройка для роли, API профиля, роли и сброс. */
final class TwoFactorHttpTest extends WebTestCase
{
    private const PASSWORD = 'password-for-test';

    private KernelBrowser $client;
    private TwoFactorService $factor;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->factor = self::getContainer()->get(TwoFactorService::class);
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.name LIKE :n')->setParameter('n', 'Test %')->execute();
        $em->createQuery('UPDATE Itk\Cms\Entity\Role r SET r.require2fa = false')->execute();
        parent::tearDown();
    }

    private function user(string $email, string $role = 'admin'): User
    {
        return self::getContainer()->get(UserManager::class)->create($email, 'Тест 2FA', $role, self::PASSWORD);
    }

    /** @return string the secret of the factor that is on */
    private function enrol(User $user): string
    {
        $secret = $this->factor->startSetup($user, 'Тест')['secret'];
        $this->factor->enable($user, Totp::code($secret, \time()));

        return $secret;
    }

    private function mustRole(): Role
    {
        $role = new Role('test-must', 'Test обязательная');
        $role->require2fa = true;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();

        return $role;
    }

    /** Signs the person in without going through the form (no code asked, as the password step of the real sign-in leaves the session unchecked). */
    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
    }

    /** The shell of a person who may open it: reads the CSRF token for API calls. */
    private function openShell(): string
    {
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        \preg_match('/"csrf":"([a-f0-9]+)"/', $html, $m);
        $this->csrf = $m[1] ?? '';

        return $html;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function otpToken(): string
    {
        $this->client->request('GET', '/admin/login/otp');
        self::assertResponseIsSuccessful();
        self::assertSame(1, \preg_match('/name="_token" value="([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m));

        return $m[1];
    }

    private function postCode(string $token, string $code): void
    {
        $this->client->request('POST', '/admin/login/otp', ['code' => $code, '_token' => $token]);
    }

    private function wrongCode(string $secret): string
    {
        $valid = [];
        for ($i = -2; $i <= 2; ++$i) {
            $valid[] = Totp::code($secret, \time() + $i * Totp::PERIOD);
        }

        return \in_array('000000', $valid, true) ? '999999' : '000000';
    }

    /* ---------- the code page ---------- */

    #[Test]
    public function withTheFactorOnNothingOpensBeforeTheCode(): void
    {
        $user = $this->user('test-code@example.com');
        $secret = $this->enrol($user);
        $this->signIn($user);

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login/otp');
        $this->client->request('GET', '/admin/preview/nothing');
        self::assertResponseRedirects('/admin/login/otp');
        $this->client->request('GET', '/admin/api/schema');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('2fa_code', \json_decode((string) $this->client->getResponse()->getContent(), true)['details']['code']);
        $this->client->request('GET', '/admin/api/2fa');
        self::assertResponseStatusCodeSame(401, 'even the profile API waits for the code');

        $token = $this->otpToken();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="code"', $page);
        self::assertStringContainsString('test-code@example.com', $page);
        self::assertStringNotContainsString($secret, $page);

        $this->postCode('', Totp::code($secret, \time() + Totp::PERIOD));
        self::assertResponseStatusCodeSame(403, 'no CSRF token');
        $this->postCode($token, $this->wrongCode($secret));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Код не подошёл', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/admin/api/schema');
        self::assertResponseStatusCodeSame(401, 'a wrong code opens nothing');

        $code = Totp::code($secret, \time() + Totp::PERIOD);
        $this->postCode($token, $code);
        self::assertResponseRedirects('/admin');
        $this->openShell();
        $this->api('GET', 'schema');

        $this->client->request('GET', '/admin/login/otp');
        self::assertResponseRedirects('/admin', null, 'nothing to ask when the session is checked');
    }

    #[Test]
    public function aRecoveryCodeOpensTheSessionOnceAndTheSameCodeNeverAgain(): void
    {
        $user = $this->user('test-recovery@example.com');
        $secret = $this->factor->startSetup($user, 'Тест')['secret'];
        $codes = $this->factor->enable($user, Totp::code($secret, \time()));
        $this->signIn($user);

        $this->postCode($this->otpToken(), $codes[0]);
        self::assertResponseRedirects('/admin');
        $this->openShell();

        $this->client->getCookieJar()->clear(); // another browser, a new password sign-in
        $this->signIn($user);
        $this->postCode($this->otpToken(), $codes[0]);
        self::assertResponseStatusCodeSame(422, 'a spent code');
    }

    #[Test]
    public function fiveWrongCodesLockThePage(): void
    {
        $user = $this->user('test-lock@example.com');
        $secret = $this->enrol($user);
        $this->signIn($user);
        $token = $this->otpToken();
        $wrong = $this->wrongCode($secret);

        for ($i = 1; $i <= 4; ++$i) {
            $this->postCode($token, $wrong);
            self::assertResponseStatusCodeSame(422);
        }
        $this->postCode($token, $wrong);
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Слишком много', (string) $this->client->getResponse()->getContent());

        $this->postCode($token, Totp::code($secret, \time() + Totp::PERIOD));
        self::assertResponseStatusCodeSame(429, 'the right code does not help while it is locked');
        $this->client->request('GET', '/admin/api/schema');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aPersonWithoutTheFactorIsNotAskedForACode(): void
    {
        $this->signIn($this->user('test-plain@example.com'));

        $html = $this->openShell();
        self::assertStringContainsString('"twoFactor":{"enabled":false,"required":false,"enrol":false}', $html);
        $this->api('GET', 'schema');
        $this->client->request('GET', '/admin/login/otp');
        self::assertResponseRedirects('/admin');
    }

    /* ---------- a role that demands the factor ---------- */

    #[Test]
    public function aDemandingRoleOpensOnlyTheSetupUntilTheFactorIsOn(): void
    {
        $this->mustRole();
        $user = $this->user('test-must@example.com', 'test-must');
        $this->signIn($user);

        $html = $this->openShell();
        self::assertStringContainsString('"enrol":true', $html);
        foreach (['schema', 'me', 'languages'] as $path) {
            $body = $this->api('GET', $path, [], 403);
            self::assertSame('2fa_enrol', $body['details']['code'], $path);
        }
        $this->client->request('GET', '/admin/preview/nothing');
        self::assertResponseRedirects('/admin');

        $status = $this->api('GET', '2fa');
        self::assertSame(['enabled' => false, 'pending' => false, 'required' => true, 'recoveryLeft' => 0, 'enabledAt' => null], $status);

        $setup = $this->api('POST', '2fa/setup');
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup['secret']);
        self::assertStringStartsWith('otpauth://totp/', $setup['uri']);
        self::assertStringContainsString('test-must%40example.com', $setup['uri']);
        $this->api('GET', 'schema', [], 403);

        $this->api('POST', '2fa/enable', ['code' => '000000'], 422);
        $done = $this->api('POST', '2fa/enable', ['code' => Totp::code($setup['secret'], \time())]);
        self::assertTrue($done['ok']);
        self::assertCount(10, $done['recoveryCodes']);

        $this->api('GET', 'schema'); // this session proved the app works: it needs no second code
        $me = $this->api('GET', 'me');
        self::assertSame(['enabled' => true, 'required' => true, 'enrol' => false], $me['twoFactor']);

        $left = $this->api('GET', '2fa');
        self::assertSame([true, 10], [$left['enabled'], $left['recoveryLeft']]);
        self::assertStringNotContainsString($setup['secret'], (string) $this->client->getResponse()->getContent(), 'the secret is shown once, at the setup');

        $this->api('POST', '2fa/disable', ['password' => self::PASSWORD], 409);
        $this->api('POST', '2fa/enable', ['code' => '123456'], 409);
    }

    /* ---------- the profile ---------- */

    #[Test]
    public function theProfileTurnsTheFactorOnAndOffWithThePassword(): void
    {
        $this->signIn($this->user('test-profile@example.com'));
        $this->openShell();

        $this->api('POST', '2fa/enable', ['code' => '123456'], 409); // no setup yet
        $setup = $this->api('POST', '2fa/setup');
        self::assertSame(['enabled' => false, 'pending' => true], \array_intersect_key($this->api('GET', '2fa'), ['enabled' => 1, 'pending' => 1]));
        $this->api('POST', '2fa/enable', ['code' => Totp::code($setup['secret'], \time())]);
        $this->api('POST', '2fa/setup', [], 409);

        $codes = $this->api('POST', '2fa/recovery-codes', ['password' => self::PASSWORD])['recoveryCodes'];
        self::assertCount(10, $codes);
        $this->api('POST', '2fa/recovery-codes', ['password' => 'nope'], 422);
        $this->api('POST', '2fa/disable', ['password' => 'nope'], 422);
        self::assertTrue($this->api('GET', '2fa')['enabled']);

        $this->api('POST', '2fa/disable', ['password' => self::PASSWORD]);
        self::assertFalse($this->api('GET', '2fa')['enabled']);
    }

    #[Test]
    public function theProfileApiNeedsTheCsrfToken(): void
    {
        $this->signIn($this->user('test-csrf@example.com'));
        $this->openShell();
        $this->csrf = 'wrong';

        $this->api('POST', '2fa/setup', [], 403);
    }

    /* ---------- roles and people (Настройки → Пользователи) ---------- */

    #[Test]
    public function anAdministratorDemandsTheFactorFromARoleIncludingBuiltInOnes(): void
    {
        $this->signIn($this->user('test-admin@example.com'));
        $this->openShell();

        $role = $this->api('POST', 'roles', ['name' => 'Test Редакторы 2FA'], 201);
        self::assertFalse($role['require2fa']);
        $role = $this->api('PATCH', 'roles/' . $role['id'], ['require2fa' => true]);
        self::assertTrue($role['require2fa']);
        self::assertSame('Test Редакторы 2FA', $role['name']);
        $roles = $this->api('GET', 'roles');
        self::assertTrue(\array_values(\array_filter($roles, static fn (array $r): bool => $r['id'] === $role['id']))[0]['require2fa']);

        $editor = $this->api('PATCH', 'roles/editor', ['require2fa' => true]);
        self::assertTrue($editor['require2fa'], 'a built-in role can only be asked for the factor');
        $this->api('PATCH', 'roles/editor', ['require2fa' => true, 'name' => 'Другое имя'], 403);
        $this->api('PATCH', 'roles/editor', ['perms' => []], 403);
        $this->api('PATCH', 'roles/editor', ['name' => 'Другое имя'], 403);
        self::assertFalse($this->api('PATCH', 'roles/editor', ['require2fa' => false])['require2fa']);
    }

    #[Test]
    public function aPersonWhoMayNotEditRolesCannotDemandTheFactor(): void
    {
        $this->signIn($this->user('test-narrow@example.com', 'requests'));
        $this->openShell();

        $this->api('PATCH', 'roles/editor', ['require2fa' => true], 403);
        $this->api('POST', 'users/anyone/reset-2fa', [], 403);
    }

    #[Test]
    public function anAdministratorResetsTheFactorOfAnotherPersonButNotOwn(): void
    {
        $other = $this->user('test-lost@example.com', 'editor');
        $this->enrol($other);
        $me = $this->user('test-admin2@example.com');
        $this->enrol($me);
        $this->signIn($me);
        $this->postCode($this->otpToken(), Totp::code($this->secretOf($me), \time() + Totp::PERIOD));
        $this->openShell();

        $list = $this->api('GET', 'users');
        $byEmail = \array_column($list, null, 'email');
        self::assertTrue($byEmail['test-lost@example.com']['twoFactor']);

        $reset = $this->api('POST', 'users/' . $other->id . '/reset-2fa');
        self::assertFalse($reset['twoFactor']);
        self::assertFalse($this->factor->isEnabled($other));
        $this->api('POST', 'users/' . $me->id . '/reset-2fa', [], 403);
        self::assertTrue($this->factor->isEnabled($me));
        $this->api('POST', 'users/nobody/reset-2fa', [], 404);
    }

    /** The plain secret of a person: only the test can ask the box for it. */
    private function secretOf(User $user): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $sealed = (string) $em->getConnection()->fetchOne('SELECT secret FROM itk_user_2fa WHERE user_id = ?', [$user->id]);

        return (string) self::getContainer()->get(\Itk\Cms\Security\TwoFactor\SecretBox::class)->open($sealed);
    }
}

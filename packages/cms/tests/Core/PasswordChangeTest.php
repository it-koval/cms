<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Пароли: «Мой профиль → Сменить пароль» (текущий + новый, лимит попыток), одноразовый пароль другому человеку, команда itk:user:password. */
final class PasswordChangeTest extends WebTestCase
{
    private const OLD = 'old-password-for-test';
    private const NEW = 'brand-new-password-1';

    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function user(string $email, string $role = 'admin'): User
    {
        return self::getContainer()->get(UserManager::class)->create($email, 'Тест пароля', $role, self::OLD);
    }

    private function signIn(User $user): void
    {
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
        self::assertNotSame('', $this->csrf);
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

    private function valid(string $email, string $password): bool
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password);
    }

    #[Test]
    public function aPersonChangesHisOwnPasswordAndStaysSignedIn(): void
    {
        $this->signIn($this->user('test-pw-self@example.com'));

        $this->api('POST', 'me/password', ['current' => self::OLD, 'password' => self::NEW, 'repeat' => self::NEW]);

        self::assertTrue($this->valid('test-pw-self@example.com', self::NEW));
        self::assertFalse($this->valid('test-pw-self@example.com', self::OLD));
        // the same session, the same CSRF token of the open page: nothing broke
        self::assertSame('test-pw-self@example.com', $this->api('GET', 'me')['email']);
        $this->api('POST', 'me/password', ['current' => self::NEW, 'password' => 'another-new-password-2']);
        self::assertTrue($this->valid('test-pw-self@example.com', 'another-new-password-2'));
    }

    #[Test]
    public function aWrongCurrentPasswordOrAWeakNewOneChangesNothing(): void
    {
        $this->signIn($this->user('test-pw-bad@example.com'));

        $wrong = $this->api('POST', 'me/password', ['current' => 'not-my-password', 'password' => self::NEW], 422);
        self::assertArrayHasKey('current', $wrong['details']);
        $short = $this->api('POST', 'me/password', ['current' => self::OLD, 'password' => 'short-9ch'], 422);
        self::assertArrayHasKey('password', $short['details']);
        self::assertStringContainsString('10', $short['error']);
        $same = $this->api('POST', 'me/password', ['current' => self::OLD, 'password' => self::OLD], 422);
        self::assertArrayHasKey('password', $same['details']);
        $mismatch = $this->api('POST', 'me/password', ['current' => self::OLD, 'password' => self::NEW, 'repeat' => self::NEW . 'x'], 422);
        self::assertArrayHasKey('repeat', $mismatch['details']);
        $this->api('POST', 'me/password', ['password' => self::NEW], 422);

        self::assertTrue($this->valid('test-pw-bad@example.com', self::OLD), 'the password is as it was');
    }

    #[Test]
    public function tooManyWrongTriesLockTheFormEvenForTheRightPassword(): void
    {
        $this->signIn($this->user('test-pw-lock@example.com'));

        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', 'me/password', ['current' => 'guess-' . $i, 'password' => self::NEW], 422);
        }
        $locked = $this->api('POST', 'me/password', ['current' => self::OLD, 'password' => self::NEW], 429);

        self::assertSame('password_locked', $locked['details']['code']);
        self::assertTrue($this->valid('test-pw-lock@example.com', self::OLD), 'the locked form changes nothing');
        // another person is not affected
        $this->signIn($this->user('test-pw-free@example.com'));
        $this->api('POST', 'me/password', ['current' => self::OLD, 'password' => self::NEW]);
    }

    #[Test]
    public function aSuccessfulChangeStartsTheCountAgain(): void
    {
        $this->signIn($this->user('test-pw-count@example.com'));

        for ($round = 0; $round < 2; ++$round) {
            for ($i = 0; $i < 4; ++$i) {
                $this->api('POST', 'me/password', ['current' => 'guess-' . $i, 'password' => self::NEW], 422);
            }
            $current = 0 === $round ? self::OLD : self::NEW;
            $next = 0 === $round ? self::NEW : 'third-password-for-test';
            $this->api('POST', 'me/password', ['current' => $current, 'password' => $next]);
        }

        self::assertTrue($this->valid('test-pw-count@example.com', 'third-password-for-test'));
    }

    #[Test]
    public function aPasswordCannotBeChangedWithoutTheCsrfToken(): void
    {
        $this->signIn($this->user('test-pw-csrf@example.com'));

        $this->client->request('POST', '/admin/api/me/password', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode(['current' => self::OLD, 'password' => self::NEW]));

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->valid('test-pw-csrf@example.com', self::OLD));
    }

    #[Test]
    public function aStrangerCannotChangeAPassword(): void
    {
        $this->client->request('POST', '/admin/api/me/password', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode(['current' => 'x', 'password' => self::NEW]));

        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403, 302]);
    }

    #[Test]
    public function resettingAnotherPersonGivesTheOneTimePasswordOnce(): void
    {
        $other = $this->user('test-pw-other@example.com', 'editor');
        $this->signIn($this->user('test-pw-admin@example.com'));

        $answer = $this->api('POST', 'users/' . $other->id . '/reset-password');

        self::assertTrue($answer['ok']);
        self::assertSame(12, \strlen($answer['password']));
        self::assertTrue($this->valid('test-pw-other@example.com', $answer['password']));
        self::assertFalse($this->valid('test-pw-other@example.com', self::OLD));
        self::assertArrayNotHasKey('password', $this->api('GET', 'users/' . $other->id), 'the password is not kept anywhere to be read again');
    }

    #[Test]
    public function aPersonCannotResetHisOwnPasswordTheOldWay(): void
    {
        $me = $this->user('test-pw-me@example.com');
        $this->signIn($me);

        $answer = $this->api('POST', 'users/' . $me->id . '/reset-password', [], 403);

        self::assertStringContainsString('в профиле', $answer['error']);
        self::assertTrue($this->valid('test-pw-me@example.com', self::OLD), 'the password is as it was');
    }

    #[Test]
    public function aNewPersonGetsTheOneTimePasswordInTheAnswerOfTheInvitation(): void
    {
        $this->signIn($this->user('test-pw-inviter@example.com'));

        $answer = $this->api('POST', 'users', ['name' => 'Новый', 'email' => 'test-pw-new@example.com', 'roleId' => 'editor'], 201);

        self::assertSame(12, \strlen($answer['password']));
        self::assertSame('invited', $answer['status']);
        self::assertTrue($this->valid('test-pw-new@example.com', $answer['password']));
    }

    private function command(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('itk:user:password'));
    }

    #[Test]
    public function theConsoleCommandSetsAPassword(): void
    {
        $this->user('test-pw-cli@example.com');
        $tester = $this->command();

        $code = $tester->execute(['email' => 'TEST-PW-CLI@example.com', '--password' => 'console-password-1'], ['interactive' => false]);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertTrue($this->valid('test-pw-cli@example.com', 'console-password-1'));
        self::assertFalse($this->valid('test-pw-cli@example.com', self::OLD));
    }

    #[Test]
    public function theConsoleCommandRefusesAShortPasswordAndAStranger(): void
    {
        $this->user('test-pw-cli2@example.com');
        $tester = $this->command();

        self::assertSame(1, $tester->execute(['email' => 'test-pw-cli2@example.com', '--password' => 'short'], ['interactive' => false]));
        self::assertTrue($this->valid('test-pw-cli2@example.com', self::OLD));
        self::assertSame(1, $tester->execute(['email' => 'test-nobody@example.com', '--password' => 'console-password-1'], ['interactive' => false]));
        self::assertSame(1, $tester->execute(['email' => 'test-pw-cli2@example.com'], ['interactive' => false]), 'no password and no prompt: nothing changes');
        self::assertTrue($this->valid('test-pw-cli2@example.com', self::OLD));
    }
}

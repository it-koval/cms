<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Security\PasswordRecovery;
use Itk\Cms\Security\TwoFactor\Totp;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Защита админки не зависит от того, где она стоит: CSRF, заголовки, второй фактор и адреса для клиента и писем определяются по МАРШРУТУ, а не по строке пути.
 * Наследник говорит, где админка: опция itk_cms.admin_path и/или prefix при импорте маршрутов (firewall и access_control тестового сайта идут следом).
 */
abstract class AdminMountTestCase extends WebTestCase
{
    use MailSupport;

    private const PASSWORD = 'password-for-test';

    protected KernelBrowser $client;

    /** Опция itk_cms.admin_path (null = по умолчанию). */
    abstract protected static function adminPath(): ?string;

    /** prefix при импорте маршрутов админки ('' = без него). */
    abstract protected static function routePrefix(): string;

    protected static function mount(): string
    {
        return static::routePrefix() . (static::adminPath() ?? '/admin');
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, static::adminPath(), static::routePrefix());
    }

    protected function setUp(): void
    {
        $this->mailerEnv();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->wipeMailSettings();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\PasswordReset p')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-mount-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.name LIKE :n')->setParameter('n', 'Test %')->execute();
        $this->restoreMailerEnv();
        parent::tearDown();
    }

    private function user(string $tag, string $role = 'admin'): User
    {
        return self::getContainer()->get(UserManager::class)->create('test-mount-' . $tag . '@example.com', 'Тест места', $role, self::PASSWORD);
    }

    /** @return array<string, mixed> window.ITK_ADMIN of the shell */
    private function shell(): array
    {
        $this->client->request('GET', static::mount());
        self::assertResponseIsSuccessful();
        self::assertSame(1, \preg_match('/window\.ITK_ADMIN = (\{.*\});<\/script>/', (string) $this->client->getResponse()->getContent(), $m));

        return (array) \json_decode($m[1], true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $path, array $headers = [], string $body = '{}'): void
    {
        $this->client->request($method, static::mount() . '/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json'] + $headers, $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[Test]
    public function theAdminIsWhereTheTestSiteHasPutIt(): void
    {
        $this->client->request('GET', static::mount() . '/login');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/admin/login');
        if ('/admin' !== static::mount()) {
            self::assertResponseStatusCodeSame(404, 'the old place answers nothing');
        }
    }

    #[Test]
    public function aChangeOfTheApiNeedsTheCsrfTokenWhereverTheAdminStands(): void
    {
        $this->client->loginUser($this->user('csrf'), 'itk_admin');
        $csrf = (string) $this->shell()['csrf'];
        self::assertNotSame('', $csrf);

        foreach ([['PATCH', 'settings'], ['PUT', 'settings/mail'], ['POST', 'users'], ['DELETE', 'users/nobody'], ['POST', 'pages']] as [$method, $path]) {
            $this->call($method, $path);
            self::assertResponseStatusCodeSame(403, $method . ' ' . $path);
            self::assertSame('csrf', $this->json()['details']['code'] ?? null, $method . ' ' . $path);
            $this->call($method, $path, ['HTTP_X_CSRF_TOKEN' => 'wrong']);
            self::assertResponseStatusCodeSame(403, $method . ' ' . $path . ' with a wrong token');
        }

        $this->call('PATCH', 'settings', ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200, 'the right token passes');
    }

    #[Test]
    public function theHeadersAndTheJsonErrorsCoverTheWholeAdmin(): void
    {
        $this->client->request('GET', static::mount() . '/login');
        $h = $this->client->getResponse()->headers;
        self::assertSame('SAMEORIGIN', $h->get('X-Frame-Options'), 'login');
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'), 'login');

        $this->client->loginUser($this->user('headers'), 'itk_admin');
        $this->call('GET', 'schema');
        self::assertResponseIsSuccessful();
        self::assertSame('same-origin', $this->client->getResponse()->headers->get('Referrer-Policy'), 'api');

        $this->call('GET', 'no-such-call');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Не найдено', $this->json()['error'] ?? null, 'an error of the api is JSON');
        self::assertSame('SAMEORIGIN', $this->client->getResponse()->headers->get('X-Frame-Options'), 'error');
    }

    #[Test]
    public function theShellGivesTheClientEveryAddressOfTheAdmin(): void
    {
        $this->client->loginUser($this->user('shell'), 'itk_admin');
        $config = $this->shell();
        $mount = static::mount();

        self::assertSame($mount, $config['adminBase']);
        self::assertSame($mount . '/api', $config['apiBase']);
        self::assertSame($mount . '/preview', $config['previewBase']);
        self::assertSame($mount . '/login', $config['loginUrl']);
        self::assertStringStartsWith($mount . '/logout', $config['logoutUrl']);
    }

    #[Test]
    public function theSecondFactorGateHoldsWhereverTheAdminStands(): void
    {
        $user = $this->user('otp');
        $factor = self::getContainer()->get(TwoFactorService::class);
        $factor->enable($user, Totp::code($factor->startSetup($user, 'Тест')['secret'], \time()));
        $this->client->loginUser($user, 'itk_admin');
        $mount = static::mount();

        $this->client->request('GET', $mount);
        self::assertResponseRedirects($mount . '/login/otp');
        $this->client->request('GET', $mount . '/preview/nothing');
        self::assertResponseRedirects($mount . '/login/otp');
        $this->call('GET', 'schema');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('2fa_code', $this->json()['details']['code']);
        $this->call('GET', '2fa');
        self::assertResponseStatusCodeSame(401, 'even the profile API waits for the code');
        $this->client->request('GET', $mount . '/login/otp');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aRoleThatDemandsTheFactorOpensOnlyTheSetupWhereverTheAdminStands(): void
    {
        $role = new Role('test-mount', 'Test место');
        $role->require2fa = true;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();
        $this->client->loginUser($this->user('enrol', 'test-mount'), 'itk_admin');
        $mount = static::mount();

        $this->shell();
        $this->call('GET', 'schema');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('2fa_enrol', $this->json()['details']['code']);
        $this->client->request('GET', $mount . '/preview/nothing');
        self::assertResponseRedirects($mount);
        $this->call('GET', '2fa');
        self::assertResponseIsSuccessful();
        $this->call('POST', '2fa/setup', ['HTTP_X_CSRF_TOKEN' => (string) $this->shell()['csrf']]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theAddressesOfTheServerFollowTheMount(): void
    {
        $admin = self::getContainer()->get(AdminPath::class);

        self::assertSame(static::mount(), $admin->base());
        self::assertSame(static::mount() . '/reset-password/abc', $admin->to('/reset-password/abc'));
        self::assertSame(static::mount() . '/api/downloads/abc', $admin->to('/api/downloads/abc'));
    }

    #[Test]
    public function theLinksOfLettersLeadToTheMount(): void
    {
        $this->useMailpit();
        $person = $this->user('mail', 'editor');

        self::getContainer()->get(PasswordRecovery::class)->sendLink($person, SendMail::INVITE, PasswordRecovery::INVITE_TTL, 'https://site.test');

        $mail = $this->queuedMail()[0];
        self::assertStringContainsString('https://site.test' . static::mount() . '/reset-password/', $mail->text);
        self::assertStringContainsString('входите по адресу https://site.test' . static::mount() . '.', $mail->text, 'the sign-in address of the letter');
    }
}

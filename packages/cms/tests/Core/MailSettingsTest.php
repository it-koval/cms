<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Entity\User;
use Itk\Cms\Mail\DsnBuilder;
use Itk\Cms\Mail\MailConfig;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\MailStatus;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\MailSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Transport\Dsn;

/** «Настройки → Почта»: строка подключения, пароль, который не уходит из сервера, права, пробное письмо. */
final class MailSettingsTest extends WebTestCase
{
    use MailSupport;

    private const PASSWORD = 'password-for-test';
    private const SECRET = 'S3cr3t!pa$$ @:/#%word';

    private KernelBrowser $client;
    private string $csrf = '';

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
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $this->mailpitWipe('test-mail-test@example.com');
        $this->restoreMailerEnv();
        parent::tearDown();
    }

    private function signIn(string $role = 'admin', string $email = 'test-mail-admin@example.com'): User
    {
        $user = self::getContainer()->get(UserManager::class)->create($email, 'Тест почты', $role, self::PASSWORD);
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
        self::assertNotSame('', $this->csrf);

        return $user;
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

    /** @return iterable<string, array{MailConfig}> */
    public static function dsnCases(): iterable
    {
        yield 'без шифрования и входа' => [new MailConfig(true, 'smtp.example.com', 25, 'none')];
        yield 'SSL с логином и паролем' => [new MailConfig(true, 'smtp.example.com', 465, 'ssl', 'robot', 'p4ss')];
        yield 'STARTTLS' => [new MailConfig(true, 'smtp.example.com', 587, 'starttls', 'robot', 'p4ss')];
        yield 'логин — адрес почты' => [new MailConfig(true, 'smtp.example.com', 587, 'starttls', 'robot@example.com', 'p4ss')];
        yield 'пароль со спецзнаками' => [new MailConfig(true, 'smtp.example.com', 587, 'starttls', 'ro bot:1', self::SECRET)];
        yield 'проценты и плюс' => [new MailConfig(true, 'smtp.example.com', 587, 'starttls', 'a+b%40c', '100%+?x=1&y=2#frag')];
        yield 'логин без пароля' => [new MailConfig(true, 'smtp.example.com', 25, 'none', 'robot')];
        yield 'русские буквы' => [new MailConfig(true, 'smtp.example.com', 587, 'starttls', 'робот', 'пароль/с:собакой@')];
    }

    #[Test]
    #[DataProvider('dsnCases')]
    public function theDsnKeepsLoginAndPasswordIntactWhateverSignsTheyHave(MailConfig $c): void
    {
        $dsn = Dsn::fromString(DsnBuilder::build($c));

        self::assertSame('ssl' === $c->encryption ? 'smtps' : 'smtp', $dsn->getScheme());
        self::assertSame('smtp.example.com', $dsn->getHost());
        self::assertSame($c->port, $dsn->getPort());
        self::assertSame('' === $c->username ? null : $c->username, $dsn->getUser());
        self::assertSame('' === $c->password ? null : $c->password, $dsn->getPassword());
        self::assertSame('none' === $c->encryption, '0' === $dsn->getOption('auto_tls') || 'false' === $dsn->getOption('auto_tls'));
        self::assertSame('starttls' === $c->encryption, $dsn->getBooleanOption('require_tls'));
    }

    #[Test]
    public function aSecretIsCutOutOfAnyTextInEveryForm(): void
    {
        $lowercaseEscape = \str_replace('%3A', '%3a', \rawurlencode(self::SECRET));
        $text = 'AUTH failed for ' . self::SECRET . ' / ' . \rawurlencode(self::SECRET) . ' / ' . \base64_encode(self::SECRET) . ' / ' . $lowercaseEscape;

        $clean = DsnBuilder::clean($text, [self::SECRET, '']);

        self::assertStringNotContainsString(self::SECRET, $clean);
        self::assertStringNotContainsString(\rawurlencode(self::SECRET), $clean);
        self::assertStringNotContainsString(\base64_encode(self::SECRET), $clean);
        self::assertSame('AUTH failed for *** / *** / *** / ***', $clean);
    }

    #[Test]
    public function thePasswordIsStoredSealedAndNeverReturned(): void
    {
        $this->signIn();

        $saved = $this->api('PUT', 'settings/mail', [
            'enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'robot',
            'password' => self::SECRET, 'fromAddress' => 'test-site@example.com', 'fromName' => 'Сайт',
        ]);
        $read = $this->api('GET', 'settings/mail');

        foreach ([$saved, $read] as $answer) {
            self::assertTrue($answer['passwordSet']);
            self::assertArrayNotHasKey('password', $answer);
            self::assertStringNotContainsString(self::SECRET, (string) \json_encode($answer, \JSON_UNESCAPED_UNICODE));
        }
        self::assertSame(['smtp.example.com', 587, 'starttls', 'robot', 'test-site@example.com', 'Сайт', true], [
            $read['host'], $read['port'], $read['encryption'], $read['username'], $read['fromAddress'], $read['fromName'], $read['enabled'],
        ]);
        // the generic settings API does not know the mail keys at all
        $all = $this->api('GET', 'settings');
        self::assertStringNotContainsString(self::SECRET, (string) \json_encode($all, \JSON_UNESCAPED_UNICODE));
        self::assertArrayNotHasKey('mail', $all);
        self::assertArrayNotHasKey('telegramBot', $all);
        self::assertArrayNotHasKey('maxBot', $all);
        self::assertArrayNotHasKey('password', $all);
        // in the database it is sealed, not text
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $row = $em->getRepository(Setting::class)->find(['key' => 'mail', 'locale' => '*']);
        self::assertNotNull($row);
        self::assertStringStartsWith('v1.', $row->value['password']);
        self::assertStringNotContainsString(self::SECRET, (string) \json_encode($row->value));
        // ... and the code reads it back for the connection
        self::assertSame(self::SECRET, self::getContainer()->get(MailSettings::class)->config()->password);
    }

    #[Test]
    public function anEmptyPasswordOnSaveKeepsTheCurrentOneAndOnlyAnExplicitClearRemovesIt(): void
    {
        $this->signIn();
        $base = ['enabled' => true, 'host' => 'smtp.example.com', 'fromAddress' => 'test-site@example.com'];
        $this->api('PUT', 'settings/mail', $base + ['password' => self::SECRET]);

        $this->api('PUT', 'settings/mail', $base + ['password' => '', 'fromName' => 'Другое имя']);
        self::assertSame(self::SECRET, self::getContainer()->get(MailSettings::class)->config()->password, 'empty input keeps it');
        $this->api('PUT', 'settings/mail', ['fromName' => 'Ещё имя']);
        self::assertSame(self::SECRET, self::getContainer()->get(MailSettings::class)->config()->password, 'a missing field keeps it');
        $this->api('PUT', 'settings/mail', ['password' => 'new-password-1']);
        self::assertSame('new-password-1', self::getContainer()->get(MailSettings::class)->config()->password, 'a new one replaces it');

        $cleared = $this->api('PUT', 'settings/mail', ['clearPassword' => true]);

        self::assertFalse($cleared['passwordSet']);
        self::assertSame('', self::getContainer()->get(MailSettings::class)->config()->password);
        self::assertSame('smtp.example.com', $cleared['host'], 'clearing the password leaves the rest');
    }

    #[Test]
    public function wrongValuesAreRefusedWithAReasonForEachField(): void
    {
        $this->signIn();

        $bad = $this->api('PUT', 'settings/mail', ['enabled' => true, 'host' => 'smtp://evil host', 'port' => 70000, 'encryption' => 'weird', 'fromAddress' => 'not-an-email', 'username' => "a\r\nb"], 422);

        foreach (['host', 'port', 'encryption', 'fromAddress', 'username'] as $field) {
            self::assertArrayHasKey($field, $bad['details'], $field);
        }
        $needs = $this->api('PUT', 'settings/mail', ['enabled' => true, 'host' => '', 'fromAddress' => ''], 422);
        self::assertArrayHasKey('host', $needs['details']);
        self::assertArrayHasKey('fromAddress', $needs['details']);
        // switched off, an unfinished form is fine
        $this->api('PUT', 'settings/mail', ['enabled' => false, 'host' => '', 'fromAddress' => '']);
        // an empty port means the usual one of the encryption
        $port = $this->api('PUT', 'settings/mail', ['encryption' => 'ssl', 'port' => '']);
        self::assertSame(465, $port['port']);
        // a form field sends the port as a string
        $text = $this->api('PUT', 'settings/mail', ['encryption' => 'starttls', 'port' => '2525']);
        self::assertSame(2525, $text['port']);
        $this->api('PUT', 'settings/mail', ['encryption' => 'starttls', 'port' => '25.5'], 422);
    }

    #[Test]
    public function onlyThoseWithTheRightSeeEditAndTestTheMailSettings(): void
    {
        $this->signIn('editor', 'test-mail-editor@example.com');

        $this->api('GET', 'settings/mail', [], 403);
        $this->api('PUT', 'settings/mail', ['enabled' => false], 403);
        $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com'], 403);

        $this->signIn('requests', 'test-mail-requests@example.com');
        $this->api('GET', 'settings/mail', [], 403);
        $this->signIn('admin', 'test-mail-admin2@example.com');
        $this->api('GET', 'settings/mail');
    }

    #[Test]
    public function aLetterIsNotSentWithoutSettingsAndTheTestSaysSo(): void
    {
        $this->signIn();

        $mail = $this->api('GET', 'settings/mail');
        self::assertFalse($mail['configured']);
        self::assertSame('none', $mail['source']);
        $no = $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com'], 422);
        self::assertStringContainsString('Почта', $no['error']);
        $this->api('POST', 'settings/mail-test', ['to' => 'not-an-email'], 422);
    }

    #[Test]
    public function theTestLetterReachesTheMailServerAndGoesToTheAdminByDefault(): void
    {
        $user = $this->signIn();
        $this->useMailpit();
        $this->mailpitWipe('test-mail-test@example.com');
        $this->mailpitWipe($user->email);

        $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com']);
        $own = $this->api('POST', 'settings/mail-test');

        $found = $this->mailpitFor('test-mail-test@example.com');
        self::assertCount(1, $found);
        self::assertStringContainsString('Проверка почты', $found[0]['Subject']);
        $message = $this->mailpitMessage($found[0]['ID']);
        self::assertSame('test-site@example.com', $message['From']['Address']);
        self::assertStringContainsString('Почта работает', $message['Text']);
        self::assertStringContainsString('Почта работает', $message['HTML']);
        self::assertSame($user->email, $own['to']);
        self::assertCount(1, $this->mailpitFor($user->email));
        $this->mailpitWipe($user->email);
    }

    #[Test]
    public function aRefusalOfTheServerIsShownWithoutThePassword(): void
    {
        $this->signIn();
        // nobody listens on port 1: the connection is refused, the login and password are in the DSN
        $this->api('PUT', 'settings/mail', [
            'enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'starttls', 'username' => 'robot', 'password' => self::SECRET, 'fromAddress' => 'test-site@example.com',
        ]);

        $fail = $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com'], 502);

        self::assertStringStartsWith('Письмо не отправлено', $fail['error']);
        self::assertStringNotContainsString(self::SECRET, (string) \json_encode($fail, \JSON_UNESCAPED_UNICODE));
        $state = $this->api('GET', 'settings/mail');
        self::assertNotEmpty($state['last']['error']);
        self::assertStringNotContainsString(self::SECRET, (string) \json_encode($state, \JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function theServerMailerDsnIsTheFallbackWhenTheSettingsAreOffOrEmpty(): void
    {
        $this->requireMailpit();
        $this->mailerEnv('smtp://' . $this->mailpitSmtpHost() . ':' . $this->mailpitSmtpPort() . '?auto_tls=false');
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->signIn();
        self::getContainer()->get(MailSettings::class)->save(['fromAddress' => 'test-site@example.com']);
        $this->mailpitWipe('test-mail-test@example.com');

        $state = $this->api('GET', 'settings/mail');
        self::assertTrue($state['configured']);
        self::assertSame('env', $state['source']);
        self::assertTrue($state['envFallback']);
        self::assertStringNotContainsString($this->mailpitSmtpHost() . ':' . $this->mailpitSmtpPort(), (string) \json_encode($state));
        $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com']);
        self::assertCount(1, $this->mailpitFor('test-mail-test@example.com'));
        // switched on with a server of its own, the settings win
        $this->api('PUT', 'settings/mail', ['enabled' => true, 'host' => $this->mailpitSmtpHost(), 'port' => $this->mailpitSmtpPort(), 'encryption' => 'none']);
        self::assertSame('settings', $this->api('GET', 'settings/mail')['source']);
    }

    #[Test]
    public function aNullTransportInTheEnvironmentDoesNotCountAsConfiguredMail(): void
    {
        $this->signIn();
        self::getContainer()->get(MailSettings::class)->save(['fromAddress' => 'test-site@example.com']);

        $state = $this->api('GET', 'settings/mail');

        self::assertFalse($state['configured']);
        self::assertFalse($state['envFallback']);
    }

    #[Test]
    public function anExistingUnencryptedSmtpPasswordIsReportedAndCannotBeUsed(): void
    {
        $this->signIn(email: 'test-f1-mail-' . \bin2hex(\random_bytes(8)) . '@example.com');
        $this->api('PUT', 'settings/mail', [
            'enabled' => true, 'host' => 'smtp.example.test', 'encryption' => 'starttls',
            'username' => 'fixture', 'password' => self::SECRET, 'fromAddress' => 'site@example.test',
        ]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $row = $em->find(Setting::class, ['key' => MailSettings::KEY, 'locale' => Setting::ALL]);
        $row->value['encryption'] = 'none'; // simulate the setting written by the previous version
        $em->flush();

        $state = $this->api('GET', 'settings/mail');
        self::assertTrue($state['passwordSet']);
        self::assertFalse($state['configured']);
        self::assertStringContainsString('STARTTLS', $state['problem']);
        $this->api('POST', 'settings/mail-test', ['to' => 'never-sent@example.test'], 422);
        self::assertTrue($this->api('PUT', 'settings/mail', ['encryption' => 'starttls'])['configured']);
    }

    #[Test]
    public function theAddressesForRequestLettersLiveInNotificationsWithItsRights(): void
    {
        $this->signIn();

        $all = $this->api('GET', 'settings');
        self::assertSame([], $all['mailRoutes']);
        self::assertFalse($all['mailReady'], 'mail is not configured yet');
        $saved = $this->api('PATCH', 'settings', ['mailRoutes' => ['*' => 'Test-Req-A@example.com ; test-req-b@example.com', 'call' => '']]);
        self::assertSame(['*' => 'test-req-a@example.com, test-req-b@example.com'], $saved['mailRoutes']);
        $this->api('PATCH', 'settings', ['mailRoutes' => ['*' => 'nope']], 422);
        $this->useMailpit();
        self::assertTrue($this->api('GET', 'settings')['mailReady']);

        $this->signIn('editor', 'test-mail-editor@example.com');
        $seen = $this->api('GET', 'settings');
        foreach (['mailRoutes', 'mailReady', 'tgRoutes', 'tgOn', 'maxRoutes', 'maxOn', 'maxFallback', 'maxReady'] as $key) {
            self::assertArrayNotHasKey($key, $seen, 'an editor does not see ' . $key);
        }
        $this->api('PATCH', 'settings', ['mailRoutes' => ['*' => 'test-req-c@example.com']], 403);
    }

    #[Test]
    public function aVeryShortSecretDoesNotGarbleTheErrorText(): void
    {
        $text = 'Expected response code 250 but got code "535", with message "535 Authentication failed for a".';

        self::assertSame($text, DsnBuilder::clean($text, ['a', 'ab', 'abc', '']), 'one to three signs are left alone');
        self::assertSame('failed for ***', DsnBuilder::clean('failed for pass', ['pass']));
    }

    #[Test]
    public function theSavedPasswordDoesNotFollowAChangeOfServerOrLogin(): void
    {
        $this->signIn();
        $base = ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'robot', 'fromAddress' => 'test-site@example.com'];
        $this->api('PUT', 'settings/mail', $base + ['password' => self::SECRET]);

        // an attacker with the right to edit points the server at his own host and leaves the password field empty
        $refused = $this->api('PUT', 'settings/mail', ['host' => 'evil.example.net', 'encryption' => 'none'], 422);
        self::assertSame('Сервер изменился — введите пароль заново', $refused['details']['password']);
        $this->api('PUT', 'settings/mail', ['username' => 'other-login', 'password' => ''], 422);

        $config = self::getContainer()->get(MailSettings::class)->config();
        self::assertSame(['smtp.example.com', 'robot', self::SECRET], [$config->host, $config->username, $config->password], 'nothing was saved');
        self::assertTrue($this->api('GET', 'settings/mail')['passwordSet']);

        // the same server in other letters, the port and the sender are not a move
        $this->api('PUT', 'settings/mail', ['host' => 'SMTP.Example.com', 'port' => 25, 'fromName' => 'Другое имя']);
        self::assertSame(self::SECRET, self::getContainer()->get(MailSettings::class)->config()->password);
        // a new password with the move is fine, and so is clearing it
        $this->api('PUT', 'settings/mail', ['host' => 'smtp2.example.com', 'password' => 'brand-new-secret']);
        $config = self::getContainer()->get(MailSettings::class)->config();
        self::assertSame(['smtp2.example.com', 'brand-new-secret'], [$config->host, $config->password]);
        $cleared = $this->api('PUT', 'settings/mail', ['host' => 'smtp3.example.com', 'clearPassword' => true]);
        self::assertFalse($cleared['passwordSet']);
        // with no password saved there is nothing to protect
        $this->api('PUT', 'settings/mail', ['host' => 'smtp4.example.com', 'username' => 'again']);
    }

    #[Test]
    public function theTestLetterIsLimitedToTenAnHourForEachPerson(): void
    {
        $this->signIn();
        for ($i = 0; $i < 10; ++$i) {
            $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com'], 422); // not configured: nothing goes out, but every try counts
        }

        $stop = $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com'], 429);

        self::assertStringContainsString('Слишком много', $stop['error']);
        $this->signIn('admin', 'test-mail-admin3@example.com');
        $this->api('POST', 'settings/mail-test', ['to' => 'test-mail-test@example.com'], 422); // another person has his own count
    }

    #[Test]
    public function theStateOfTheLastSendingIsKeptWithPlainSqlAndReadBackFresh(): void
    {
        $this->signIn();
        $status = self::getContainer()->get(MailStatus::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $status->failed('reset', 'Не вышло: сервер ответил отказом');
        self::assertSame('Не вышло: сервер ответил отказом', $status->get()['error']);
        self::assertNotNull($status->get()['errorAt']);
        $row = $em->getRepository(Setting::class)->find(['key' => 'mailStatus', 'locale' => '*']); // the manager now remembers this row
        self::assertNotNull($row);
        $status->sent();

        $now = $status->get();
        self::assertNull($now['error']);
        self::assertNotNull($now['okAt']);
        self::assertTrue($em->isOpen());
        $status->failed('invite', 'Опять отказ');
        self::assertSame(['invite', 'Опять отказ'], [$status->get()['kind'], $status->get()['error']]);
        self::assertSame($now['okAt'], $status->get()['okAt'], 'the last success is kept');
    }
}

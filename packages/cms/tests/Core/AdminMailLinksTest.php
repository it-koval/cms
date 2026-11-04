<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\Entity\User;
use Itk\Cms\Mail\MailerFactory;
use Itk\Cms\Mail\MailStatus;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\MailSupport;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** «Пригласить» и «Сбросить пароль»: письмо со ссылкой, когда почта настроена (и честный ответ), окно с одноразовым паролем, когда нет. */
final class AdminMailLinksTest extends WebTestCase
{
    use MailSupport;

    private const OLD = 'old-password-for-test';
    private const NEW = 'brand-new-password-1';

    private KernelBrowser $client;
    private string $csrf = '';
    private int $n = 0;

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
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-adm-%@example.com')->execute();
        $this->restoreMailerEnv();
        parent::tearDown();
    }

    private function email(string $tag): string
    {
        return 'test-adm-' . $tag . '-' . \bin2hex(\random_bytes(3)) . '@example.com';
    }

    private function admin(): User
    {
        $user = self::getContainer()->get(UserManager::class)->create($this->email('boss'), 'Администратор теста', 'admin', self::OLD);
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
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

    private function valid(string $email, string $password): bool
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password);
    }

    /** A visitor with a session of his own opens the link of a letter and sets the password. */
    private function setPasswordByLink(SendMail $mail, string $password): void
    {
        $client = $this->client;
        $client->getCookieJar()->clear();
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('test', 1);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        $server = ['REMOTE_ADDR' => '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . (++$this->n)];
        $client->request('GET', \parse_url($this->linkIn($mail), \PHP_URL_PATH), [], [], $server);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        \preg_match('/name="_token" value="([a-f0-9]+)"/', (string) $client->getResponse()->getContent(), $m);
        $client->request('POST', \parse_url($this->linkIn($mail), \PHP_URL_PATH), ['password' => $password, 'repeat' => $password, '_token' => $m[1] ?? ''], [], $server);
        self::assertTrue($client->getResponse()->isRedirect('/admin/login?notice=password-set'), (string) $client->getResponse()->getStatusCode());
    }

    #[Test]
    public function withoutMailTheInvitationAndTheResetHandBackAOneTimePassword(): void
    {
        $this->admin();
        $email = $this->email('nomail');

        $invited = $this->api('POST', 'users', ['name' => 'Новый человек', 'email' => $email, 'roleId' => 'editor'], 201);

        self::assertFalse($invited['emailed']);
        self::assertSame(12, \strlen($invited['password']));
        self::assertArrayNotHasKey('emailError', $invited);
        self::assertTrue($this->valid($email, $invited['password']));
        self::assertSame([], $this->queuedMail());

        $reset = $this->api('POST', 'users/' . $invited['id'] . '/reset-password');

        self::assertFalse($reset['emailed']);
        self::assertTrue($reset['invited']);
        self::assertNotSame($invited['password'], $reset['password']);
        self::assertTrue($this->valid($email, $reset['password']));
        self::assertFalse($this->valid($email, $invited['password']), 'the dialog password replaced the old one');
        self::assertSame([], $this->queuedMail());
    }

    #[Test]
    public function withMailTheInvitationIsALinkForSevenDaysAndNoPasswordIsHandedOut(): void
    {
        $this->useMailpit();
        $this->admin();
        $email = $this->email('invite');

        $invited = $this->api('POST', 'users', ['name' => 'Новый человек', 'email' => $email, 'roleId' => 'editor'], 201);

        self::assertTrue($invited['emailed']);
        self::assertArrayNotHasKey('password', $invited);
        self::assertSame('invited', $invited['status']);
        $queued = $this->queuedMail();
        self::assertCount(1, $queued);
        self::assertSame([$email, SendMail::INVITE], [$queued[0]->to, $queued[0]->kind]);
        self::assertStringContainsString('7 дней', $queued[0]->text);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $link = $em->getRepository(PasswordReset::class)->findOneBy(['user' => $invited['id']]);
        self::assertSame(PasswordReset::INVITE, $link->purpose);
        $left = $link->expiresAt->getTimestamp() - \time();
        self::assertGreaterThan(6 * 86400 + 86000, $left);
        self::assertLessThanOrEqual(7 * 86400, $left);
        // the person opens the link and sets the password, and signs in with it
        $this->setPasswordByLink($queued[0], self::NEW);
        self::assertTrue($this->valid($email, self::NEW));
    }

    #[Test]
    public function withMailAResetSendsALinkAndTheOldPasswordStaysUntilANewOneIsSet(): void
    {
        $this->useMailpit();
        $this->admin();
        $person = self::getContainer()->get(UserManager::class)->create($this->email('reset'), 'Забывчивый', 'editor', self::OLD);

        $reset = $this->api('POST', 'users/' . $person->id . '/reset-password');

        self::assertTrue($reset['emailed']);
        self::assertSame($person->email, $reset['email']);
        self::assertArrayNotHasKey('password', $reset);
        self::assertTrue($this->valid($person->email, self::OLD), 'nothing changed until the link is used');
        $queued = $this->queuedMail();
        self::assertSame([$person->email, SendMail::RESET], [$queued[0]->to, $queued[0]->kind]);
        self::assertStringContainsString('24 часа', $queued[0]->text);
        $this->setPasswordByLink($queued[0], self::NEW);
        self::assertTrue($this->valid($person->email, self::NEW));
        self::assertFalse($this->valid($person->email, self::OLD));
    }

    #[Test]
    public function aResetForAPersonWhoNeverSignedInIsTheInvitationAgain(): void
    {
        $this->useMailpit();
        $this->admin();
        $invited = $this->api('POST', 'users', ['name' => 'Ещё не заходил', 'email' => $this->email('again'), 'roleId' => 'editor'], 201);
        $this->clearQueue();

        $again = $this->api('POST', 'users/' . $invited['id'] . '/reset-password');

        self::assertTrue($again['emailed']);
        self::assertTrue($again['invited']);
        self::assertSame(SendMail::INVITE, $this->queuedMail()[0]->kind);
    }

    #[Test]
    public function theLetterReallyGoesOutThroughTheMailServer(): void
    {
        $this->useMailpit();
        $this->admin();
        $email = $this->email('wire');
        $this->mailpitWipe($email);
        $this->api('POST', 'users', ['name' => 'Проверка', 'email' => $email, 'roleId' => 'editor'], 201);

        self::getContainer()->get(SiteMailer::class)->deliver($this->queuedMail()[0]);

        $found = $this->mailpitFor($email);
        self::assertCount(1, $found);
        self::assertStringContainsString('пригласили', $this->mailpitMessage($found[0]['ID'])['Subject']);
        $this->mailpitWipe($email);
    }

    #[Test]
    public function aResetStillNeedsTheRightAndNeverWorksOnYourself(): void
    {
        $this->useMailpit();
        $me = $this->admin();

        $this->api('POST', 'users/' . $me->id . '/reset-password', [], 403);
        self::assertSame([], $this->queuedMail());
    }

    #[Test]
    public function whenTheQueueRefusesTheLetterTheAdminGetsThePasswordAndTheReason(): void
    {
        // the dead queue is put in place before anything uses the real one
        self::getContainer()->set('messenger.bus.default', new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('queue is down');
            }
        });
        $this->useMailpit();
        $this->admin();
        $person = self::getContainer()->get(UserManager::class)->create($this->email('down'), 'Очередь лежит', 'editor', self::OLD);

        $reset = $this->api('POST', 'users/' . $person->id . '/reset-password');

        self::assertFalse($reset['emailed']);
        self::assertSame(12, \strlen($reset['password']));
        self::assertStringContainsString('очередь', $reset['emailError']);
        self::assertTrue($this->valid($person->email, $reset['password']));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(0, $em->getRepository(PasswordReset::class)->count(['user' => $person->id]), 'no link is left behind');
    }

    #[Test]
    public function queuingWithoutMailOrWithADeadQueueIsAnErrorNotAFalseOk(): void
    {
        $factory = self::getContainer()->get(MailerFactory::class);
        $status = self::getContainer()->get(MailStatus::class);
        $down = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('queue is down');
            }
        };
        $mail = new SendMail('test-adm-x@example.com', 'Тема', 'Текст', '<p>Текст</p>', SendMail::TEST);

        try {
            (new SiteMailer($factory, $status, $down, new NullLogger()))->queue($mail);
            self::fail('mail is not configured');
        } catch (ContentException $e) {
            self::assertSame(503, $e->status());
            self::assertSame('mail_not_configured', $e->details()['code']);
        }
        $this->useMailpit();
        try {
            (new SiteMailer(self::getContainer()->get(MailerFactory::class), $status, $down, new NullLogger()))->queue($mail);
            self::fail('the queue is down');
        } catch (ContentException $e) {
            self::assertSame('mail_queue', $e->details()['code']);
        }
    }

    /** A visitor with a session of his own opens the page of the link; its status code. */
    private function linkStatus(SendMail $mail): int
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', \parse_url($this->linkIn($mail), \PHP_URL_PATH), [], [], ['REMOTE_ADDR' => '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . (++$this->n)]);

        return $this->client->getResponse()->getStatusCode();
    }

    #[Test]
    public function aLinkSentToAMistypedAddressDiesWhenTheAdministratorCorrectsTheAddress(): void
    {
        $this->useMailpit();
        $this->admin();
        $invited = $this->api('POST', 'users', ['name' => 'Опечатка в адресе', 'email' => $this->email('typo'), 'roleId' => 'editor'], 201);
        $mail = $this->queuedMail()[0];
        self::assertSame(200, $this->linkStatus($mail), 'the link works until the address is corrected');
        $this->admin2();

        $this->api('PATCH', 'users/' . $invited['id'], ['email' => $this->email('fixed')]);

        self::assertSame(410, $this->linkStatus($mail), 'the stranger who got the letter cannot use it any more');
    }

    #[Test]
    public function aChangedNameOrRoleLeavesTheLinkAlive(): void
    {
        $this->useMailpit();
        $this->admin();
        $invited = $this->api('POST', 'users', ['name' => 'Имя', 'email' => $this->email('name'), 'roleId' => 'editor'], 201);
        $mail = $this->queuedMail()[0];
        $this->admin2();

        $this->api('PATCH', 'users/' . $invited['id'], ['name' => 'Другое имя']);

        self::assertSame(200, $this->linkStatus($mail));
    }

    #[Test]
    public function aLinkDiesWhenThePersonIsBlockedOrGetsAOneTimePassword(): void
    {
        $this->useMailpit();
        $this->admin();
        $person = self::getContainer()->get(UserManager::class)->create($this->email('blocked'), 'Блокируемый', 'editor', self::OLD);
        $this->api('POST', 'users/' . $person->id . '/reset-password');
        $mail = $this->queuedMail()[0];
        $this->admin2();
        $this->api('POST', 'users/' . $person->id . '/block');
        self::assertSame(410, $this->linkStatus($mail), 'blocked');

        $other = self::getContainer()->get(UserManager::class)->create($this->email('onetime'), 'С одноразовым паролем', 'editor', self::OLD);
        $this->admin2();
        $this->api('POST', 'users/' . $other->id . '/reset-password');
        $second = $this->queuedMail()[0];
        $this->clearQueue();
        self::assertSame(200, $this->linkStatus($second));
        $this->admin2();
        $this->wipeMailSettings(); // mail is gone: the administrator gets a one-time password instead
        $one = $this->api('POST', 'users/' . $other->id . '/reset-password');
        self::assertFalse($one['emailed']);

        self::assertSame(410, $this->linkStatus($second), 'a one-time password burns the open link');
    }

    /** Signs in as the administrator again (the link checks forget the session). */
    private function admin2(): void
    {
        $this->client->getCookieJar()->clear();
        $this->admin();
    }
}

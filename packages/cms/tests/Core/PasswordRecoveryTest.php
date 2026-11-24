<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\Entity\User;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Security\PasswordRecovery;
use Itk\Cms\Security\RequestPasswordLink;
use Itk\Cms\Security\RequestPasswordLinkHandler;
use Itk\Cms\Security\TwoFactor\Totp;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\MailSupport;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** «Забыли пароль?»: одинаковый ответ, письмо со ссылкой, хеш вместо ключа в базе, срок, один раз, лимиты, новый пароль и двухэтапный вход. */
final class PasswordRecoveryTest extends WebTestCase
{
    use MailSupport;

    private const OLD = 'old-password-for-test';
    private const NEW = 'brand-new-password-1';
    private const NEUTRAL = 'Если такой адрес есть, мы отправили письмо со ссылкой.';

    private KernelBrowser $client;
    private int $n = 0;
    /** @var list<SendMail> letters put into the queue by the pages (the in-memory queue of a test is emptied after every request) */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->mailerEnv();
        $this->client = static::createClient();
        $this->client->disableReboot();
        // a visitor who has not signed in still has a session (the form token is tied to it); the mock storage of tests sets no cookie by itself
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('test', 1);
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    protected function tearDown(): void
    {
        $this->wipeMailSettings();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-rec-%@example.com')->execute();
        $this->restoreMailerEnv();
        parent::tearDown();
    }

    private function user(string $tag, string $role = 'editor'): User
    {
        return self::getContainer()->get(UserManager::class)->create('test-rec-' . $tag . '-' . \bin2hex(\random_bytes(3)) . '@example.com', 'Тест восстановления', $role, self::OLD);
    }

    /** Each test has addresses of its own (127.0.0.x), so the limit of one test does not touch another. */
    private function ip(): string
    {
        return '10.' . \random_int(1, 250) . '.' . \random_int(1, 250) . '.' . (++$this->n);
    }

    /**
     * The visitor asks at the page; then the worker does its half (the page only queues RequestPasswordLink): the test runs the handler for the messages of this request.
     *
     * @param array<string, string> $server extra server values of the request (Host, ...)
     */
    private function forgot(string $email, ?string $ip = null, bool $withToken = true, array $server = [], bool $work = true): string
    {
        $server += ['REMOTE_ADDR' => $ip ?? $this->ip()];
        $this->client->request('GET', '/admin/forgot-password', [], [], $server);
        \preg_match('/name="_token" value="([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->client->request('POST', '/admin/forgot-password', ['email' => $email, '_token' => $withToken ? ($m[1] ?? '') : 'wrong'], [], $server);
        $answer = (string) $this->client->getResponse()->getContent();
        if ($work) {
            $this->runWorker();
        }

        return $answer;
    }

    /** @return list<RequestPasswordLink> the requests the last page queued */
    private function queuedRequests(): array
    {
        $out = [];
        foreach (self::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof RequestPasswordLink) {
                $out[] = $envelope->getMessage();
            }
        }

        return $out;
    }

    private function runWorker(): void
    {
        $handler = self::getContainer()->get(RequestPasswordLinkHandler::class);
        foreach ($this->queuedRequests() as $request) {
            $handler($request);
        }
        \array_push($this->sent, ...$this->queuedMail());
    }

    /** @return array{0: string, 1: string} the page of the link: its token and the CSRF token of its form */
    private function openLink(SendMail $mail, ?string $ip = null): array
    {
        $url = $this->linkIn($mail);
        $this->client->request('GET', \parse_url($url, \PHP_URL_PATH), [], [], ['REMOTE_ADDR' => $ip ?? $this->ip()]);
        \preg_match('/name="_token" value="([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);

        return [\basename($url), $m[1] ?? ''];
    }

    private function setPassword(string $token, string $csrf, string $password, ?string $repeat = null, ?string $ip = null): void
    {
        $this->client->request('POST', '/admin/reset-password/' . $token, ['password' => $password, 'repeat' => $repeat ?? $password, '_token' => $csrf], [], ['REMOTE_ADDR' => $ip ?? $this->ip()]);
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
    public function theLoginPageHasTheLinkAndTheNoticeAfterAReset(): void
    {
        $this->client->request('GET', '/admin/login');
        self::assertSelectorTextContains('a.forgot', 'Забыли пароль?');
        self::assertSame('/admin/forgot-password', $this->client->getCrawler()->filter('a.forgot')->attr('href'));

        $this->client->request('GET', '/admin/login?notice=password-set');
        self::assertSelectorTextContains('.notice', 'Пароль сохранён');
        $this->client->request('GET', '/admin/login?notice=<script>');
        self::assertSelectorNotExists('.notice');
    }

    #[Test]
    public function withoutMailThePageSendsThePersonToTheAdministratorAndHasNoForm(): void
    {
        $this->client->request('GET', '/admin/forgot-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lead', 'Обратитесь к администратору сайта');
        self::assertSelectorNotExists('form');
        self::assertStringNotContainsString('bin/console', (string) $this->client->getResponse()->getContent(), 'the console command is for the README, not for visitors');
        $user = $this->user('nomail');
        $html = $this->forgot($user->email);
        self::assertStringNotContainsString(self::NEUTRAL, $html);
        self::assertSame([], $this->sent, 'nothing is sent');
    }

    #[Test]
    public function theAnswerIsTheSameForAKnownAndAnUnknownAddressAndOnlyAKnownOneGetsALetter(): void
    {
        $this->useMailpit();
        $known = $this->user('known');

        $a = $this->forgot($known->email);
        self::assertStringContainsString(self::NEUTRAL, $a);
        self::assertCount(1, $this->sent);
        $b = $this->forgot('test-rec-nobody-' . \bin2hex(\random_bytes(3)) . '@example.com');

        self::assertStringContainsString(self::NEUTRAL, $b);
        self::assertCount(1, $this->sent, 'an unknown address queued nothing');
        $strip = static fn (string $h): string => (string) \preg_replace('/name="_token" value="[a-f0-9]+"/', '', $h);
        self::assertSame($strip($a), $strip($b), 'the two pages are the same, down to the letter');
    }

    #[Test]
    public function aBlockedPersonGetsNothingAndAWrongFormTokenSendsNothing(): void
    {
        $this->useMailpit();
        $blocked = $this->user('blocked');
        $blocked->status = User::BLOCKED;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->forgot($blocked->email);
        $fine = $this->user('csrf');
        $page = $this->forgot($fine->email, null, false);

        self::assertSame([], $this->sent);
        self::assertStringNotContainsString(self::NEUTRAL, $page);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function theLinkHoldsARandomKeyAndTheDatabaseHoldsOnlyItsHash(): void
    {
        $this->useMailpit();
        $user = $this->user('hash');
        $this->forgot($user->email);
        $mail = $this->sent[0];
        $token = \basename($this->linkIn($mail));

        self::assertGreaterThanOrEqual(43, \strlen($token), '32 random bytes');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $rows = $em->getRepository(PasswordReset::class)->findBy(['user' => $user->id]);
        self::assertCount(1, $rows);
        self::assertSame(\hash('sha256', $token), $rows[0]->tokenHash);
        self::assertStringNotContainsString($token, \json_encode([$rows[0]->tokenHash, $rows[0]->id, $rows[0]->purpose]));
        self::assertSame(SendMail::RESET, $mail->kind);
        $left = $rows[0]->expiresAt->getTimestamp() - \time();
        self::assertGreaterThan(3500, $left);
        self::assertLessThanOrEqual(3600, $left, 'one hour');
        self::assertStringContainsString('1 час', $mail->text);
        // another visit gets another key
        $this->forgot($user->email);
        self::assertNotSame($token, \basename($this->linkIn($this->sent[1])));
    }

    #[Test]
    public function theLetterReachesTheMailServerWithAWorkingLink(): void
    {
        $this->useMailpit();
        $user = $this->user('mailpit');
        $this->mailpitWipe($user->email);
        $this->forgot($user->email);

        self::getContainer()->get(\Itk\Cms\Mail\SiteMailer::class)->deliver($this->sent[0]);

        $found = $this->mailpitFor($user->email);
        self::assertCount(1, $found);
        $message = $this->mailpitMessage($found[0]['ID']);
        self::assertStringContainsString('Новый пароль', $message['Subject']);
        self::assertStringContainsString('/admin/reset-password/', $message['HTML']);
        self::assertStringContainsString('/admin/reset-password/', $message['Text']);
        self::assertStringNotContainsString('&amp;', $message['Text']);
        $this->mailpitWipe($user->email);
    }

    #[Test]
    public function theNewPasswordIsSetTheLinkDiesAndTheOtherLinksOfThePersonToo(): void
    {
        $this->useMailpit();
        $user = $this->user('set');
        $this->forgot($user->email);
        $this->forgot($user->email);
        [$first, $second] = $this->sent;
        [$token, $csrf] = $this->openLink($first);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name=password]');

        $this->setPassword($token, $csrf, self::NEW);

        self::assertResponseRedirects('/admin/login?notice=password-set');
        self::assertTrue($this->valid($user->email, self::NEW));
        self::assertFalse($this->valid($user->email, self::OLD));
        $this->openLink($first);
        self::assertResponseStatusCodeSame(410, 'single use');
        self::assertSelectorTextContains('h1', 'Ссылка не действует');
        $this->openLink($second);
        self::assertResponseStatusCodeSame(410, 'the other link of the person is dead too');
        $this->setPassword(\basename($this->linkIn($second)), $csrf, 'another-password-22');
        self::assertResponseStatusCodeSame(410);
        self::assertTrue($this->valid($user->email, self::NEW), 'a dead link changes nothing');
        $this->client->followRedirects(false);
        $this->client->request('GET', '/admin/login?notice=password-set');
        self::assertSelectorTextContains('.notice', 'Пароль сохранён');
    }

    #[Test]
    public function anExpiredLinkDoesNotWork(): void
    {
        $this->useMailpit();
        $user = $this->user('late');
        $this->forgot($user->email);
        $mail = $this->sent[0];
        [$token, $csrf] = $this->openLink($mail);
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE Itk\Cms\Entity\PasswordReset p SET p.expiresAt = :d WHERE p.user = :u')->setParameter('d', new \DateTimeImmutable('-1 minute'))->setParameter('u', $user->id)->execute();

        $this->openLink($mail);
        self::assertResponseStatusCodeSame(410);
        $this->setPassword($token, $csrf, self::NEW);
        self::assertResponseStatusCodeSame(410);
        self::assertTrue($this->valid($user->email, self::OLD));
    }

    #[Test]
    public function aGuessedOrBrokenLinkIsGone(): void
    {
        foreach (['x', \str_repeat('a', 43), 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'] as $token) {
            $this->client->request('GET', '/admin/reset-password/' . $token, [], [], ['REMOTE_ADDR' => $this->ip()]);
            self::assertResponseStatusCodeSame(410);
        }
        $this->client->request('GET', '/admin/reset-password/' . \str_repeat('a', 300), [], [], ['REMOTE_ADDR' => $this->ip()]);
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aWeakPasswordOrADifferentRepeatKeepsTheLinkAlive(): void
    {
        $this->useMailpit();
        $user = $this->user('weak');
        $this->forgot($user->email);
        [$token, $csrf] = $this->openLink($this->sent[0]);

        $this->setPassword($token, $csrf, 'short-9ch');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', '10');
        $this->setPassword($token, $csrf, self::NEW, self::NEW . 'x');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.error', 'не совпадают');
        $this->setPassword($token, 'wrong-csrf', self::NEW);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->valid($user->email, self::OLD));

        $this->setPassword($token, $csrf, self::NEW);
        self::assertResponseRedirects();
        self::assertTrue($this->valid($user->email, self::NEW));
    }

    #[Test]
    public function oneAddressGetsAtMostThreeLettersAnHour(): void
    {
        $this->useMailpit();
        $user = $this->user('perMail');
        for ($i = 0; $i < 5; ++$i) {
            $answer = $this->forgot($user->email);
            self::assertStringContainsString(self::NEUTRAL, $answer, 'the visitor sees no difference');
        }

        self::assertCount(3, $this->sent);
    }

    #[Test]
    public function oneVisitorCanAskOnlyTenTimesAnHour(): void
    {
        $this->useMailpit();
        $ip = $this->ip();
        for ($i = 0; $i < 10; ++$i) {
            self::assertStringContainsString(self::NEUTRAL, $this->forgot('test-rec-flood' . $i . '-' . \bin2hex(\random_bytes(3)) . '@example.com', $ip));
        }

        $this->forgot('test-rec-flood-last@example.com', $ip);

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.error', 'Слишком много попыток');
    }

    #[Test]
    public function tooManyWrongLinksFromOneVisitorAreStopped(): void
    {
        $ip = $this->ip();
        for ($i = 0; $i < 10; ++$i) {
            $this->client->request('GET', '/admin/reset-password/' . \str_repeat('b', 30 + $i), [], [], ['REMOTE_ADDR' => $ip]);
            self::assertResponseStatusCodeSame(410);
        }

        $this->client->request('GET', '/admin/reset-password/' . \str_repeat('c', 40), [], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseStatusCodeSame(429);
    }

    #[Test]
    public function theSecondFactorStaysAfterAReset(): void
    {
        $this->useMailpit();
        $user = $this->user('2fa', 'admin');
        $factor = self::getContainer()->get(TwoFactorService::class);
        $secret = $factor->startSetup($user, 'Тест')['secret'];
        $factor->enable($user, Totp::code($secret, \time()));
        $this->forgot($user->email);
        [$token, $csrf] = $this->openLink($this->sent[0]);

        $this->setPassword($token, $csrf, self::NEW);

        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->getRepository(User::class)->findOneBy(['email' => $user->email]);
        self::assertTrue($factor->isEnabled($fresh), 'the code is still asked at the next sign-in');
        self::assertTrue($this->valid($user->email, self::NEW));
    }

    #[Test]
    public function aSignedInPersonIsSentBackToTheAdmin(): void
    {
        $user = $this->user('signed');
        $this->client->loginUser($user, 'itk_admin');

        $this->client->request('GET', '/admin/forgot-password');

        self::assertResponseRedirects('/admin');
    }

    #[Test]
    public function theLinkPageIsNotCachedAndSendsNoReferrer(): void
    {
        $this->useMailpit();
        $user = $this->user('headers');
        $this->forgot($user->email);
        $this->openLink($this->sent[0]);

        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $headers->get('X-Robots-Tag'));
        self::assertInstanceOf(PasswordRecovery::class, self::getContainer()->get(PasswordRecovery::class));
    }

    /** Sets the setting «domain» for a test; the returned function puts it back as it was. */
    private function setDomain(SettingsService $settings, string $domain): \Closure
    {
        $had = $settings->isSet('domain');
        $before = $settings->get('domain', null, '');
        $settings->set('domain', $domain);

        return static function () use ($settings, $had, $before): void {
            if ($had) {
                $settings->set('domain', $before);
            } else {
                self::getContainer()->get(EntityManagerInterface::class)->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key = 'domain'")->execute();
            }
        };
    }

    /** @return list<string> the SQL statements that changed data in the last request (needs enableProfiler() before it) */
    private function writes(): array
    {
        $profile = $this->client->getProfile();
        self::assertNotFalse($profile, 'the profiler is on');
        $out = [];
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $q) {
                if (\preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', (string) $q['sql'])) {
                    $out[] = \trim((string) \preg_replace('/\s+/', ' ', (string) $q['sql']));
                }
            }
        }

        return $out;
    }

    #[Test]
    public function aKnownAndAnUnknownAddressDoTheSameWorkInTheRequest(): void
    {
        $this->useMailpit();
        $known = $this->user('same');
        $addresses = [$known->email, 'test-rec-nobody-' . \bin2hex(\random_bytes(3)) . '@example.com'];
        $writes = [];
        $queued = [];
        foreach ($addresses as $email) {
            $server = ['REMOTE_ADDR' => $this->ip()];
            $this->client->request('GET', '/admin/forgot-password', [], [], $server);
            \preg_match('/name="_token" value="([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
            $this->client->enableProfiler();
            $this->client->request('POST', '/admin/forgot-password', ['email' => $email, '_token' => $m[1] ?? ''], [], $server);
            $writes[] = $this->writes();
            $queued[] = \array_map(static fn (object $o): string => $o::class, \array_map(static fn ($e) => $e->getMessage(), self::getContainer()->get('messenger.transport.async')->getSent()));
            self::assertStringContainsString(self::NEUTRAL, (string) $this->client->getResponse()->getContent());
            // nothing about the person was done in the request: no link row, no letter
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            self::assertSame(0, $em->getRepository(PasswordReset::class)->count(['user' => $known->id]), 'the link is made by the worker');
            self::assertSame([], $this->queuedMail());
        }

        self::assertSame($writes[0], $writes[1], 'the same data changes for a known and an unknown address');
        self::assertSame([RequestPasswordLink::class], $queued[0]);
        self::assertSame($queued[0], $queued[1], 'the same message goes to the queue');
    }

    #[Test]
    public function theWorkerMakesTheLinkOnTheAddressThePageResolvedAndSkipsAnUnknownOrBlockedPerson(): void
    {
        $this->useMailpit();
        $known = $this->user('worker');
        $blocked = $this->user('workerblocked');
        $blocked->status = User::BLOCKED;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $handler = self::getContainer()->get(RequestPasswordLinkHandler::class);

        $handler(new RequestPasswordLink($known->email, 'https://site.example'));
        $handler(new RequestPasswordLink($blocked->email, 'https://site.example'));
        $handler(new RequestPasswordLink('test-rec-nobody@example.com', 'https://site.example'));

        $mails = $this->queuedMail();
        self::assertCount(1, $mails);
        self::assertSame($known->email, $mails[0]->to);
        self::assertStringStartsWith('https://site.example/admin/reset-password/', $this->linkIn($mails[0]));
    }

    #[Test]
    public function aForgedHostDoesNotDecideWhereTheLinkLeadsWhenTheSiteHasADomain(): void
    {
        $this->useMailpit();
        $settings = self::getContainer()->get(SettingsService::class);
        $restoreDomain = $this->setDomain($settings, 'test-link.example.com');
        $hosts = Request::getTrustedHosts();
        Request::setTrustedHosts([]);
        try {
            $user = $this->user('forged');
            $this->forgot($user->email, null, true, ['HTTP_HOST' => 'evil.example']);

            self::assertCount(1, $this->sent);
            $url = $this->linkIn($this->sent[0]);
            self::assertStringStartsWith('https://test-link.example.com/admin/reset-password/', $url);
            self::assertStringNotContainsString('evil.example', $this->sent[0]->text . $this->sent[0]->html);
        } finally {
            Request::setTrustedHosts($hosts);
            $restoreDomain();
        }
    }

    #[Test]
    public function withTrustedHostsTheHostOfTheRequestIsUsedAndAnotherOneIsRefused(): void
    {
        $this->useMailpit();
        $settings = self::getContainer()->get(SettingsService::class);
        $restoreDomain = $this->setDomain($settings, 'test-link.example.com');
        $hosts = Request::getTrustedHosts();
        Request::setTrustedHosts(['^test-web\.example\.com$']);
        try {
            $user = $this->user('trusted');
            $this->forgot($user->email, null, true, ['HTTP_HOST' => 'test-web.example.com']);
            self::assertStringStartsWith('http://test-web.example.com/admin/reset-password/', $this->linkIn($this->sent[0]));

            $this->forgot($user->email, null, true, ['HTTP_HOST' => 'evil.example']);
            self::assertResponseStatusCodeSame(400);
            self::assertCount(1, $this->sent, 'no letter for a forged Host');
        } finally {
            Request::setTrustedHosts($hosts);
            $restoreDomain();
        }
    }

    #[Test]
    public function withNoTrustedAddressInProductionNoLinkIsMadeAndTheAnswerStaysTheSame(): void
    {
        $this->useMailpit();
        $settings = self::getContainer()->get(SettingsService::class);
        $restoreDomain = $this->setDomain($settings, '');
        $hosts = Request::getTrustedHosts();
        Request::setTrustedHosts([]);
        try {
            $stack = new RequestStack();
            $stack->push(Request::create('http://evil.example/admin/forgot-password'));
            $production = new PublicUrl($stack, $settings, null, false);
            $development = new PublicUrl($stack, $settings, null, true);
            $configured = new PublicUrl($stack, $settings, 'https://test-set.example.com/', false);

            self::assertNull($production->base());
            self::assertSame('/admin', $production->to('/admin'), 'a path stays relative; password links refuse before that');
            self::assertSame('http://evil.example', $development->base(), 'debug mode: the request as it came');
            self::assertSame('https://test-set.example.com', $configured->base(), 'the option wins over everything');
            self::assertSame('https://test-set.example.com/admin/x', $configured->to('/admin/x'));
            self::assertSame('https://other.example/admin/x', $configured->to('/admin/x', 'https://other.example'), 'an address resolved earlier is used as it is');
            $settings->set('domain', 'http://test-plain.example.com/');
            self::assertSame('http://test-plain.example.com', $production->base(), 'a domain with a scheme keeps it');

            $settings->set('domain', '');
            self::getContainer()->set(PublicUrl::class, $production); // the site as in production: debug off
            $user = $this->user('nolink');
            $html = $this->forgot($user->email);

            self::assertStringContainsString(self::NEUTRAL, $html);
            self::assertSame([], $this->queuedRequests(), 'nothing is queued without an address to build the link on');
            self::assertSame([], $this->sent);
        } finally {
            Request::setTrustedHosts($hosts);
            $restoreDomain();
        }
    }

    #[Test]
    public function anOpenLinkDiesWhenThePersonSetsAPasswordInAnyOtherWay(): void
    {
        $this->useMailpit();
        $user = $this->user('profile');
        $this->forgot($user->email);
        $this->openLink($this->sent[0]);
        self::assertResponseIsSuccessful();

        self::getContainer()->get(UserManager::class)->setPassword($user, 'changed-in-the-profile-1');

        $this->openLink($this->sent[0]);
        self::assertResponseStatusCodeSame(410, 'a changed password takes the open links away');
    }
}

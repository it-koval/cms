<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Entity\UserTwoFactor;
use Itk\Cms\Repository\UserTwoFactorRepository;
use Itk\Cms\Security\TwoFactor\SecretBox;
use Itk\Cms\Security\TwoFactor\Totp;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Двухэтапный вход: TOTP по RFC 6238, окно времени, одноразовость кодов, резервные коды, шифрование секрета, блокировка, «обязательно для роли». */
final class TwoFactorTest extends KernelTestCase
{
    private const PASSWORD = 'password-for-test';

    private TwoFactorService $factor;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factor = self::getContainer()->get(TwoFactorService::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute(); // itk_user_2fa goes with them
        $this->em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.name LIKE :n')->setParameter('n', 'Test %')->execute();
        parent::tearDown();
    }

    private function user(string $email = 'test-2fa@example.com', string $role = Role::EDITOR): User
    {
        return self::getContainer()->get(UserManager::class)->create($email, 'Тест 2FA', $role, self::PASSWORD);
    }

    /**
     * A person with the factor switched on: [user, secret].
     *
     * @return array<array-key, mixed>
     */
    private function enrolled(string $email = 'test-2fa@example.com', string $role = Role::EDITOR): array
    {
        $user = $this->user($email, $role);
        $secret = $this->factor->startSetup($user, 'Тест')['secret'];
        $this->factor->enable($user, Totp::code($secret, \time()));

        return [$user, $secret];
    }

    private function wrongCode(string $secret): string
    {
        $valid = [];
        for ($i = -2; $i <= 2; ++$i) {
            $valid[] = Totp::code($secret, \time() + $i * Totp::PERIOD);
        }
        foreach (['000000', '111111', '222222', '333333'] as $c) {
            if (!\in_array($c, $valid, true)) {
                return $c;
            }
        }

        return '999999';
    }

    /* ---------- RFC 6238 ---------- */

    #[Test]
    public function totpMatchesTheRfc6238Vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890'); // the SHA-1 seed of RFC 6238, appendix B
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        self::assertSame('12345678901234567890', Totp::base32Decode($secret));

        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $code) {
            self::assertSame($code, Totp::code($secret, $time, 8), "T = $time");
        }
        self::assertSame('287082', Totp::code($secret, 59), 'six digits are the last six of the eight');
    }

    #[Test]
    public function aCodeIsAcceptedInsideTheWindowOnlyAndOnce(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $t = 1111111111;
        $step = \intdiv($t, 30);

        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $t), $t));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $t - 30), $t), 'one step late: a phone clock a little behind');
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $t + 30), $t), 'one step early');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $t - 60), $t), 'two steps away is out of the window');
        self::assertNull(Totp::verify($secret, Totp::code($secret, $t + 60), $t));
        self::assertSame($step - 2, Totp::verify($secret, Totp::code($secret, $t - 60), $t, null, 2), 'a wider window is a parameter');

        self::assertNull(Totp::verify($secret, Totp::code($secret, $t), $t, $step), 'the step that was used is not accepted again');
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $t + 30), $t, $step), 'a later step is');
        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $t)[0] . ' ' . \substr(Totp::code($secret, $t), 1), $t), 'spaces in the typed code do not matter');
        foreach (['', '12345', '1234567', 'abcdef', '12 34'] as $bad) {
            self::assertNull(Totp::verify($secret, $bad, $t), "«{$bad}»");
        }
    }

    #[Test]
    public function theUriHasWhatAuthenticatorAppsRead(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'ivan@example.com', 'Мой сайт: тест');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString(\rawurlencode('ivan@example.com'), $uri);
        self::assertStringContainsString('issuer=' . \rawurlencode('Мой сайт  тест'), $uri, 'a colon in the issuer would break the label');
        self::assertStringContainsString('digits=6&period=30', $uri);
    }

    /* ---------- secret and codes at rest ---------- */

    #[Test]
    public function theSecretIsSealedAndRecoveryCodesAreOnlyHashed(): void
    {
        $user = $this->user();
        $setup = $this->factor->startSetup($user, 'Тест');
        $codes = $this->factor->enable($user, Totp::code($setup['secret'], \time()));

        $this->em->clear();
        $row = self::getContainer()->get(UserTwoFactorRepository::class)->findOneBy(['user' => $user->id]);
        self::assertInstanceOf(UserTwoFactor::class, $row);
        self::assertStringStartsWith('v1.', $row->secret);
        self::assertStringNotContainsString($setup['secret'], $row->secret);
        $raw = (string) $this->em->getConnection()->fetchOne('SELECT secret FROM itk_user_2fa WHERE user_id = ?', [$user->id]);
        self::assertStringNotContainsString($setup['secret'], $raw);
        self::assertSame($setup['secret'], self::getContainer()->get(SecretBox::class)->open($row->secret), 'the box opens it again');

        self::assertCount(10, $codes);
        self::assertCount(10, $row->recoveryCodes);
        $stored = (string) \json_encode($row->recoveryCodes);
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code);
            self::assertStringNotContainsString(TwoFactorService::normalizeRecoveryCode($code), $stored, 'a code is not kept as text');
        }
        self::assertCount(10, \array_unique($codes));
    }

    #[Test]
    public function theBoxDoesNotOpenWhatWasNotSealedByIt(): void
    {
        $box = self::getContainer()->get(SecretBox::class);
        $sealed = $box->seal('JBSWY3DPEHPK3PXP');

        self::assertNotSame($sealed, $box->seal('JBSWY3DPEHPK3PXP'), 'a new nonce every time');
        self::assertSame('JBSWY3DPEHPK3PXP', $box->open($sealed));
        self::assertNull($box->open('JBSWY3DPEHPK3PXP'));
        self::assertNull($box->open('v1.' . \base64_encode('short')));
        self::assertNull($box->open(\substr($sealed, 0, -3) . 'AAA'), 'a damaged value');
        self::assertNull((new SecretBox('another-app-secret'))->open($sealed), 'another APP_SECRET is another key');
        self::assertNotSame($box->hash('abc'), (new SecretBox('another-app-secret'))->hash('abc'));
    }

    /* ---------- setup ---------- */

    #[Test]
    public function theFactorProtectsTheSignInOnlyAfterTheFirstCodeConfirmsIt(): void
    {
        $user = $this->user();
        self::assertFalse($this->factor->isEnabled($user));

        $secret = $this->factor->startSetup($user, 'Тест')['secret'];
        self::assertTrue($this->factor->status($user)['pending']);
        self::assertFalse($this->factor->isEnabled($user), 'a pending secret protects nothing');
        self::assertFalse($this->factor->verify($user, Totp::code($secret, \time())), 'and does not check a code at the sign-in');

        $again = $this->factor->startSetup($user, 'Тест')['secret'];
        self::assertNotSame($secret, $again, 'a new start gives a new secret');

        try {
            $this->factor->enable($user, $this->wrongCode($again));
            self::fail('a wrong first code');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
        self::assertFalse($this->factor->isEnabled($user));

        self::assertCount(10, $this->factor->enable($user, Totp::code($again, \time())));
        self::assertTrue($this->factor->isEnabled($user));
        self::assertSame(10, $this->factor->status($user)['recoveryLeft']);
        $this->expectException(ContentException::class);
        $this->factor->startSetup($user, 'Тест'); // already on
    }

    /* ---------- sign-in ---------- */

    #[Test]
    public function aCodeWorksOnceAndAnOldOneNever(): void
    {
        [$user, $secret] = $this->enrolled();

        self::assertFalse($this->factor->verify($user, Totp::code($secret, \time())), 'the code that confirmed the setup is spent');
        $next = Totp::code($secret, \time() + Totp::PERIOD);
        self::assertTrue($this->factor->verify($user, $next), 'the next one is in the window');
        self::assertFalse($this->factor->verify($user, $next), 'the same one twice is not');
        self::assertFalse($this->factor->verify($user, 'abcdef'));
        self::assertFalse($this->factor->verify($user, ''));
    }

    #[Test]
    public function aRecoveryCodeWorksOnce(): void
    {
        $user = $this->user();
        $secret = $this->factor->startSetup($user, 'Тест')['secret'];
        $codes = $this->factor->enable($user, Totp::code($secret, \time()));

        self::assertTrue($this->factor->verify($user, \strtoupper($codes[3])), 'case does not matter');
        self::assertFalse($this->factor->verify($user, $codes[3]), 'spent');
        self::assertSame(9, $this->factor->status($user)['recoveryLeft']);
        self::assertTrue($this->factor->verify($user, \str_replace('-', ' ', $codes[7])), 'a space for the dash is fine');
        self::assertFalse($this->factor->verify($user, 'aaaaa-aaaaa'));
        self::assertFalse($this->factor->verify($user, \substr($codes[0], 0, 5)), 'half a code is not a code');
        self::assertSame(8, $this->factor->status($user)['recoveryLeft']);
    }

    #[Test]
    public function fiveWrongAnswersLockTheFactorEvenForTheRightCode(): void
    {
        [$user, $secret] = $this->enrolled();
        $wrong = $this->wrongCode($secret);

        for ($i = 1; $i <= 4; ++$i) {
            self::assertFalse($this->factor->verify($user, $wrong), "try $i");
        }
        try {
            $this->factor->verify($user, $wrong);
            self::fail('the fifth wrong answer locks');
        } catch (ContentException $e) {
            self::assertSame(429, $e->status());
            self::assertSame('2fa_locked', $e->details()['code']);
        }
        self::assertGreaterThan(0, $this->factor->lockedFor($user));
        self::assertLessThanOrEqual(900, $this->factor->lockedFor($user));

        $this->expectException(ContentException::class);
        $this->factor->verify($user, Totp::code($secret, \time() + Totp::PERIOD)); // the right code does not help while locked
    }

    #[Test]
    public function aRightCodeClearsTheCountOfMistakes(): void
    {
        [$user, $secret] = $this->enrolled();
        $wrong = $this->wrongCode($secret);
        for ($i = 0; $i < 4; ++$i) {
            $this->factor->verify($user, $wrong);
        }
        self::assertTrue($this->factor->verify($user, Totp::code($secret, \time() + Totp::PERIOD)));
        for ($i = 0; $i < 4; ++$i) {
            self::assertFalse($this->factor->verify($user, $wrong), 'four more are still not a lock');
        }
    }

    /* ---------- the gate state, the password, the role ---------- */

    #[Test]
    public function theSessionIsCheckedOnlyAfterTheCode(): void
    {
        [$user] = $this->enrolled();
        $session = new Session(new MockArraySessionStorage());

        self::assertSame(TwoFactorService::CODE, $this->factor->gate($user, $session));
        $this->factor->markVerified($user, $session);
        self::assertSame(TwoFactorService::OK, $this->factor->gate($user, $session));

        $other = $this->user('test-other@example.com');
        self::assertSame(TwoFactorService::OK, $this->factor->gate($other, $session), 'a person without the factor and without a demanding role goes in');
        $this->factor->forget($session);
        self::assertSame(TwoFactorService::CODE, $this->factor->gate($user, $session), 'a new password sign-in starts unchecked');
    }

    #[Test]
    public function theSessionGetsANewIdWhenTheSignInCodeIsAccepted(): void
    {
        [$user] = $this->enrolled();
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $session->set('keep', 'me');
        $before = $session->getId();

        $this->factor->markVerified($user, $session);
        self::assertSame($before, $session->getId(), 'turning the factor on from the profile keeps the session (the admin\'s CSRF token is made from its id)');

        $this->factor->forget($session);
        $this->factor->markVerified($user, $session, true);
        self::assertNotSame($before, $session->getId(), 'the sign-in code page is a step up in rights: the id changes');
        self::assertSame('me', $session->get('keep'), 'what the session held stays');
        self::assertSame(TwoFactorService::OK, $this->factor->gate($user, $session));
    }

    #[Test]
    public function aRoleThatDemandsTheFactorSendsTheOneWithoutItToSetUp(): void
    {
        $role = new Role('test-must', 'Test обязательная');
        $role->require2fa = true;
        $this->em->persist($role);
        $this->em->flush();
        $user = $this->user('test-must@example.com', 'test-must');
        $session = new Session(new MockArraySessionStorage());

        self::assertTrue($this->factor->isRequired($user));
        self::assertSame(TwoFactorService::ENROL, $this->factor->gate($user, $session));
        self::assertSame(['enabled' => false, 'pending' => false, 'required' => true], \array_intersect_key($this->factor->status($user), ['enabled' => 1, 'pending' => 1, 'required' => 1]));

        $secret = $this->factor->startSetup($user, 'Тест')['secret'];
        self::assertSame(TwoFactorService::ENROL, $this->factor->gate($user, $session), 'a pending setup is not enough');
        $this->factor->enable($user, Totp::code($secret, \time()));
        self::assertSame(TwoFactorService::CODE, $this->factor->gate($user, $session), 'with the factor on, the normal sign-in asks for the code');

        try {
            $this->factor->disable($user, self::PASSWORD);
            self::fail('a demanding role cannot switch it off');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
        }
        self::assertTrue($this->factor->isEnabled($user));
    }

    #[Test]
    public function disablingAsksForThePasswordAndRecoveryCodesAreRenewedWithIt(): void
    {
        [$user, $secret] = $this->enrolled();
        $old = $this->factor->regenerateRecoveryCodes($user, self::PASSWORD);

        foreach ([[fn () => $this->factor->disable($user, 'wrong-password')], [fn () => $this->factor->regenerateRecoveryCodes($user, '')]] as [$call]) {
            try {
                $call();
                self::fail('a wrong password');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
        self::assertTrue($this->factor->isEnabled($user));

        $new = $this->factor->regenerateRecoveryCodes($user, self::PASSWORD);
        self::assertNotSame($old, $new);
        self::assertFalse($this->factor->verify($user, $old[0]), 'the old codes are gone');
        self::assertTrue($this->factor->verify($user, $new[0]));

        $this->factor->disable($user, self::PASSWORD);
        self::assertFalse($this->factor->isEnabled($user));
        self::assertFalse($this->factor->status($user)['pending']);
    }

    #[Test]
    public function anAdministratorCanTakeTheFactorAway(): void
    {
        [$user] = $this->enrolled();

        self::assertTrue($this->factor->reset($user));
        self::assertFalse($this->factor->isEnabled($user));
        self::assertFalse($this->factor->reset($user), 'nothing left to reset');
    }
}

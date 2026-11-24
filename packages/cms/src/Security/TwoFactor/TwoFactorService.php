<?php

declare(strict_types=1);

namespace Itk\Cms\Security\TwoFactor;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\User;
use Itk\Cms\Entity\UserTwoFactor;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Repository\UserTwoFactorRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The second sign-in factor: a one-time code from an authenticator app (TOTP, Totp) or one of ten recovery codes.
 *  - setup: startSetup() makes a pending secret, enable() confirms it with the first code and hands out the recovery codes (shown once, stored as keyed hashes);
 *  - sign-in: the password opens the session, but the session counts as «checked» only after verify() (the gate is TwoFactorGate);
 *  - a role with `require2fa` makes people without a second factor set it up before they get anywhere (gate() says `enrol`);
 *  - five wrong codes (or wrong passwords where a password is asked) lock the factor for 15 minutes; a code works once.
 * Nothing here writes a secret or a code to a log.
 */
final class TwoFactorService
{
    public const RECOVERY_COUNT = 10;
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;
    public const SESSION_KEY = 'itk_2fa_ok';

    /** What the gate should do with the person: let him in, ask for the code, or send him to set the factor up. */
    public const OK = 'ok';
    public const CODE = 'code';
    public const ENROL = 'enrol';

    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // no letters that look like digits
    private const RECOVERY_LENGTH = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTwoFactorRepository $rows,
        private readonly RoleRepository $roles,
        private readonly SecretBox $box,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ?ClockInterface $clock = null,
        #[Autowire(service: 'limiter.itk_two_factor')]
        private readonly ?RateLimiterFactoryInterface $attempts = null,
    ) {
    }

    /* ---------- state ---------- */

    public function isEnabled(User $user): bool
    {
        return $this->rows->ofUser($user)?->isEnabled() ?? false;
    }

    /** Does the role of the person demand a second factor? */
    public function isRequired(User $user): bool
    {
        return $this->roles->find($user->role)->require2fa ?? false;
    }

    /**
     * @return array{enabled: bool, pending: bool, required: bool, recoveryLeft: int, enabledAt: string|null}
     */
    public function status(User $user): array
    {
        $row = $this->rows->ofUser($user);

        return [
            'enabled' => $row?->isEnabled() ?? false,
            'pending' => null !== $row && !$row->isEnabled(),
            'required' => $this->isRequired($user),
            'recoveryLeft' => $row?->isEnabled() ? \count($row->recoveryCodes) : 0,
            'enabledAt' => $row?->enabledAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** What to do with a signed-in person on this session: self::OK | CODE | ENROL. */
    public function gate(User $user, SessionInterface $session): string
    {
        if ($this->isEnabled($user)) {
            return $session->get(self::SESSION_KEY) === $user->id ? self::OK : self::CODE;
        }

        return $this->isRequired($user) ? self::ENROL : self::OK;
    }

    /**
     * The session is marked as checked. At the sign-in code page (`$stepUp`) the session also gets a new id: the person gains full rights there.
     * Not when he turns the factor on from the profile: he is already in, and the admin's CSRF token is made from the session id, so the page in front of him would stop working.
     */
    public function markVerified(User $user, SessionInterface $session, bool $stepUp = false): void
    {
        if ($stepUp) {
            $session->migrate(true);
        }
        $session->set(self::SESSION_KEY, $user->id);
    }

    public function forget(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    /** @return array{secret: string, uri: string} */
    public function startSetup(User $user, string $issuer): array
    {
        return $this->withLock($user, fn (): array => $this->doStartSetup($user, $issuer), false);
    }

    /** @return list<string> */
    public function enable(User $user, #[\SensitiveParameter] string $code): array
    {
        return $this->withLock($user, fn (): array => $this->doEnable($user, $code));
    }

    public function disable(User $user, #[\SensitiveParameter] string $password): void
    {
        $this->withLock($user, fn () => $this->doDisable($user, $password));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user, #[\SensitiveParameter] string $password): array
    {
        return $this->withLock($user, fn (): array => $this->doRegenerateRecoveryCodes($user, $password));
    }

    public function reset(User $user): bool
    {
        $reset = $this->withLock($user, fn (): bool => $this->doReset($user), false);
        $this->attempts?->create($user->id)->reset();

        return $reset;
    }

    public function verify(User $user, #[\SensitiveParameter] string $input): bool
    {
        return $this->withLock($user, fn (): bool => $this->doVerify($user, $input));
    }

    /* ---------- setup ---------- */

    /**
     * Starts (or restarts) the setup: a new pending secret. Nothing protects the sign-in until enable().
     *
     * @return array{secret: string, uri: string}
     */
    private function doStartSetup(User $user, string $issuer): array
    {
        $row = $this->rows->ofUser($user);
        if (null !== $row && $row->isEnabled()) {
            throw ContentException::conflict(I18n::t('http.2fa_already_on'));
        }
        $secret = Totp::newSecret();
        if (null === $row) {
            $this->em->persist(new UserTwoFactor($user, $this->box->seal($secret)));
        } else {
            $row->secret = $this->box->seal($secret);
        }
        $this->em->flush();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, $issuer)];
    }

    /**
     * Confirms the setup with the first code from the app.
     *
     * @return list<string> the recovery codes, in plain text: this is the only time they are shown
     */
    private function doEnable(User $user, #[\SensitiveParameter] string $code): array
    {
        $row = $this->rows->ofUser($user);
        if (null === $row || $row->isEnabled()) {
            throw ContentException::conflict(I18n::t('http.2fa_start_first'));
        }
        $this->mustNotBeLocked($row);
        $step = $this->checkTotp($row, $code);
        if (null === $step) {
            $this->fail($row, I18n::t('http.2fa_code_wrong_site'));
        }
        $codes = $this->newRecoveryCodes($row);
        $row->enabledAt = $this->now();
        $row->lastStep = $step;
        $this->resetFailures($row);
        $this->em->flush();

        return $codes;
    }

    /** Switches the second factor off. Asks for the password; refused where the role demands the factor. */
    private function doDisable(User $user, #[\SensitiveParameter] string $password): void
    {
        $row = $this->rows->ofUser($user);
        if (null === $row) {
            return;
        }
        if (!$row->isEnabled()) { // a setup that was not confirmed: nothing to protect, no password needed
            $this->em->remove($row);
            $this->em->flush();

            return;
        }
        if ($this->isRequired($user)) {
            throw ContentException::conflict(I18n::t('http.2fa_required_for_role'));
        }
        $this->mustNotBeLocked($row);
        $this->checkPassword($user, $row, $password);
        $this->em->remove($row);
        $this->em->flush();
    }

    /**
     * Ten new recovery codes instead of the old ones.
     *
     * @return list<string>
     */
    private function doRegenerateRecoveryCodes(User $user, #[\SensitiveParameter] string $password): array
    {
        $row = $this->rows->ofUser($user);
        if (null === $row || !$row->isEnabled()) {
            throw ContentException::conflict(I18n::t('http.2fa_not_on'));
        }
        $this->mustNotBeLocked($row);
        $this->checkPassword($user, $row, $password);
        $codes = $this->newRecoveryCodes($row);
        $this->em->flush();

        return $codes;
    }

    /** An administrator takes the second factor away from a person (a lost phone and no recovery codes): he sets it up again at the next sign-in. */
    private function doReset(User $user): bool
    {
        $row = $this->rows->ofUser($user);
        if (null === $row) {
            return false;
        }
        $this->em->remove($row);
        $this->em->flush();

        return true;
    }

    /* ---------- sign-in ---------- */

    /**
     * Checks what the person typed on the code page: a code from the app, or a recovery code (which is spent).
     * A wrong answer counts toward the lock; a locked factor answers 429 even to a right code.
     */
    private function doVerify(User $user, #[\SensitiveParameter] string $input): bool
    {
        $row = $this->rows->ofUser($user);
        if (null === $row || !$row->isEnabled()) {
            return false;
        }
        $this->mustNotBeLocked($row);

        $compact = \preg_replace('/\s+/', '', $input) ?? '';
        if (1 === \preg_match('/^\d{' . Totp::DIGITS . '}$/', $compact)) {
            $step = $this->checkTotp($row, $compact);
            if (null !== $step) {
                $row->lastStep = $step;
                $this->resetFailures($row);
                $this->em->flush();

                return true;
            }
        } elseif ($this->useRecoveryCode($row, $input)) {
            $this->resetFailures($row);
            $this->em->flush();

            return true;
        }
        try {
            $this->fail($row, I18n::t('http.2fa_code_wrong'));
        } catch (ContentException $e) {
            if (429 === $e->status()) {
                throw $e;
            }
        }

        return false;
    }

    /** Seconds left until the factor can be tried again, 0 when it is not locked. */
    public function lockedFor(User $user): int
    {
        $row = $this->rows->ofUser($user);

        return null === $row ? 0 : $this->secondsLocked($row);
    }

    /* ---------- internals ---------- */

    /**
     * Serialize all factor changes on the parent user, including setup before a factor row exists.
     * Expected validation errors are committed: a wrong code must retain its failure count.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withLock(User $user, callable $operation, bool $limited = true): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $failure = null;
        try {
            $managed = $this->em->find(User::class, $user->id);
            if (null === $managed) {
                throw ContentException::notFound(I18n::t('http.user_removed'));
            }
            $this->em->lock($managed, LockMode::PESSIMISTIC_WRITE);
            $row = $this->rows->ofUser($managed);
            if (null !== $row) {
                $this->em->refresh($row, LockMode::PESSIMISTIC_WRITE);
            }
            try {
                if ($limited && null !== $row) {
                    $this->mustNotBeLocked($row);
                }
                if ($limited && null !== $this->attempts && !$this->attempts->create($user->id)->consume()->isAccepted()) {
                    throw new ContentException($this->lockedMessage(), 429, ['code' => '2fa_locked', 'seconds' => self::LOCK_MINUTES * 60]);
                }
                $result = $operation();
                if ($limited && false !== $result) {
                    $this->attempts?->create($user->id)->reset();
                }
            } catch (ContentException $error) {
                $failure = $error;
                $result = null;
            }
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();

            throw $error;
        }
        if (null !== $failure) {
            throw $failure;
        }

        return $result;
    }

    private function checkTotp(UserTwoFactor $row, #[\SensitiveParameter] string $code): ?int
    {
        $secret = $this->box->open($row->secret);
        if (null === $secret) {
            return null;
        }

        return Totp::verify($secret, $code, $this->now()->getTimestamp(), $row->lastStep);
    }

    private function useRecoveryCode(UserTwoFactor $row, #[\SensitiveParameter] string $input): bool
    {
        $normal = self::normalizeRecoveryCode($input);
        if (self::RECOVERY_LENGTH !== \strlen($normal)) {
            return false;
        }
        $hash = $this->box->hash($normal);
        $left = [];
        $matched = false;
        foreach ($row->recoveryCodes as $stored) {
            if (!$matched && \hash_equals((string) $stored, $hash)) {
                $matched = true; // spent: it is not kept
                continue;
            }
            $left[] = $stored;
        }
        if ($matched) {
            $row->recoveryCodes = $left;
        }

        return $matched;
    }

    /** @return list<string> */
    private function newRecoveryCodes(UserTwoFactor $row): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; ++$i) {
            $plain = '';
            for ($j = 0; $j < self::RECOVERY_LENGTH; ++$j) {
                $plain .= self::RECOVERY_ALPHABET[\random_int(0, \strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = \substr($plain, 0, 5) . '-' . \substr($plain, 5);
            $hashes[] = $this->box->hash($plain);
        }
        $row->recoveryCodes = $hashes;

        return $codes;
    }

    public static function normalizeRecoveryCode(#[\SensitiveParameter] string $input): string
    {
        return \preg_replace('/[^a-z0-9]/', '', \strtolower($input)) ?? '';
    }

    private function checkPassword(User $user, UserTwoFactor $row, #[\SensitiveParameter] string $password): void
    {
        if ('' === $password || !$this->hasher->isPasswordValid($user, $password)) {
            $this->fail($row, I18n::t('http.password_wrong'));
        }
        $this->resetFailures($row);
    }

    /** Counts a wrong answer, locks the factor on the fifth, and says so. */
    private function fail(UserTwoFactor $row, string $message): never
    {
        ++$row->failedAttempts;
        if ($row->failedAttempts >= self::MAX_FAILURES) {
            $row->failedAttempts = 0;
            $row->lockedUntil = $this->now()->modify('+' . self::LOCK_MINUTES . ' minutes');
            $this->em->flush();

            throw new ContentException($this->lockedMessage(), 429, ['code' => '2fa_locked', 'seconds' => self::LOCK_MINUTES * 60]);
        }
        $this->em->flush();

        throw ContentException::invalid($message);
    }

    private function mustNotBeLocked(UserTwoFactor $row): void
    {
        $seconds = $this->secondsLocked($row);
        if ($seconds > 0) {
            throw new ContentException($this->lockedMessage(), 429, ['code' => '2fa_locked', 'seconds' => $seconds]);
        }
    }

    private function secondsLocked(UserTwoFactor $row): int
    {
        return null === $row->lockedUntil ? 0 : \max(0, $row->lockedUntil->getTimestamp() - $this->now()->getTimestamp());
    }

    private function lockedMessage(): string
    {
        return I18n::t('http.2fa_locked', ['count' => self::LOCK_MINUTES]);
    }

    private function resetFailures(UserTwoFactor $row): void
    {
        $row->failedAttempts = 0;
        $row->lockedUntil = null;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}

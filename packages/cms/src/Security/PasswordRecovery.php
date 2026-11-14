<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\PasswordReset;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\MailComposer;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Repository\PasswordResetRepository;
use Itk\Cms\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Setting a password through a link in a letter: «Забыли пароль?» on the login page, and «Пригласить» / «Сбросить пароль» of the administrator when the site can send mail.
 *
 * A link holds a random token (32 bytes). The database keeps only its sha256 hash, the link works once and until it expires (1 hour for «забыл пароль», 24 hours when an administrator
 * resets it, 7 days for an invitation). Setting a password by any way (a link, the profile, an administrator, the console) burns every unused link of the person; so do a changed address and a block. The second sign-in factor is not touched: after the reset the usual sign-in
 * (password, then the code) applies. Other sessions of the person end by themselves because the password hash changed.
 *
 * «Забыл пароль» always looks the same from outside: the same answer, and the same work in the request for a known and an unknown address (the visitor's request only
 * queues RequestPasswordLink; the worker finds the person and writes the letter), so neither the words nor the time tell whether the address exists.
 * It is limited per address and per visitor.
 */
final class PasswordRecovery
{
    public const RESET_TTL = 'PT1H';
    public const ADMIN_RESET_TTL = 'PT24H';
    public const INVITE_TTL = 'P7D';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordResetRepository $links,
        private readonly UserRepository $users,
        private readonly UserManager $manager,
        private readonly SiteMailer $mailer,
        private readonly MailComposer $composer,
        private readonly PublicUrl $urls,
        private readonly AdminPath $admin,
        private readonly LoggerInterface $logger,
        private readonly AdminLocale $locale,
        #[Autowire(service: 'messenger.bus.default')]
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'limiter.itk_password_reset_ip')]
        private readonly RateLimiterFactoryInterface $perVisitor,
        #[Autowire(service: 'limiter.itk_password_reset_email')]
        private readonly RateLimiterFactoryInterface $perAddress,
        #[Autowire(service: 'limiter.itk_password_reset_guess')]
        private readonly RateLimiterFactoryInterface $guesses,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->mailer->isConfigured();
    }

    /**
     * «Забыли пароль?»: queues the request for the worker, which sends a link if the address is a known, not blocked person. The caller shows the same answer in every case.
     * Whatever the address is, the work here is the same (the limits, one message into the queue): nothing is read about the person in the request.
     *
     * @return bool false when the visitor asked too often (the page may say so; it does not tell anything about the address)
     */
    public function request(string $email, ?string $ip): bool
    {
        if (!$this->perVisitor->create($ip ?? 'unknown')->consume()->isAccepted()) {
            return false;
        }
        $email = \mb_strtolower(\trim($email));
        if ('' === $email || \strlen($email) > 180) {
            return true;
        }
        if (!$this->perAddress->create(\hash('sha256', $email))->consume()->isAccepted()) {
            return true; // the owner of the address is not buried in letters; the visitor learns nothing
        }
        if (!$this->isAvailable()) {
            return true;
        }
        $base = $this->urls->base();
        if (null === $base) {
            $this->logger->error('A password link was asked for, but the site has no trusted address for it: set itk_cms.public_url, framework.trusted_hosts or the setting «domain»');

            return true;
        }
        try {
            $this->bus->dispatch(new RequestPasswordLink($email, $base));
        } catch (\Throwable $e) {
            $this->logger->error('Could not queue a password link request: {error}', ['error' => $e->getMessage()]);
        }

        return true;
    }

    /** The worker's half of request(): a known, not blocked person gets the letter, anybody else nothing. Errors go to the log only. */
    public function sendRequested(string $email, string $baseUrl): void
    {
        $user = $this->users->findOneByEmail($email);
        if (null === $user || User::BLOCKED === $user->status || !$this->isAvailable()) {
            return;
        }
        try {
            $this->sendLink($user, SendMail::RESET, self::RESET_TTL, $baseUrl);
        } catch (\Throwable $e) {
            $this->logger->error('Could not send a password link: {error}', ['error' => $e->getMessage()]);
        }
    }

    /** Takes every unused link of a person out of use (his password or address changed, or he was blocked). */
    public function revokeLinks(string $userId): void
    {
        $this->links->burnAllOf($userId, new \DateTimeImmutable());
    }

    /**
     * Makes a link for a person and puts the letter into the queue.
     *
     * @param string $kind SendMail::RESET | SendMail::INVITE
     * @param string $ttl how long the link works (an ISO 8601 interval)
     * @param ?string $base the trusted address of the site (null = PublicUrl now; the worker passes the one resolved in the request)
     *
     * @throws ContentException when mail is not configured, the site has no trusted address for the link or the queue did not take the letter (no link is kept then)
     */
    public function sendLink(User $user, string $kind, string $ttl, ?string $base = null): void
    {
        $base ??= $this->urls->base();
        if (null === $base) {
            $this->logger->error('A password link was not made: the site has no trusted address (itk_cms.public_url, framework.trusted_hosts or the setting «domain»)');

            throw new ContentException(I18n::t('http.recovery_no_url'), 503, ['code' => 'no_public_url']);
        }
        $now = new \DateTimeImmutable();
        $expires = $now->add(new \DateInterval($ttl));
        $token = \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
        $link = new PasswordReset($user, self::hash($token), SendMail::INVITE === $kind ? PasswordReset::INVITE : PasswordReset::RESET, $expires);
        $this->em->persist($link);
        $this->em->flush();

        try {
            // the letter speaks the language of the person it goes to, whoever sends it: himself, an administrator, the worker
            $mail = $this->locale->runFor($user, fn (): SendMail => $this->composer->passwordLink($user, $kind, $this->urls->to($this->admin->to('/reset-password/' . $token), $base), self::words($now, $expires), $this->urls->to($this->admin->to(), $base)));
            $this->mailer->queue($mail);
        } catch (\Throwable $e) {
            $this->em->remove($link);
            $this->em->flush();

            throw $e instanceof ContentException ? $e : new ContentException(I18n::t('http.mail_queue_failed'), 503);
        }
        $this->links->purge($now);
    }

    /** The link is alive: not used, not expired. Wrong guesses are counted per visitor. */
    public function find(string $token, ?string $ip = null): ?PasswordReset
    {
        if (!\preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token)) {
            $this->guesses->create($ip ?? 'unknown')->consume();

            return null;
        }
        $link = $this->links->findByHash(self::hash($token));
        if (null === $link || !$link->isUsable()) {
            $this->guesses->create($ip ?? 'unknown')->consume();

            return null;
        }

        return $link;
    }

    /** Too many wrong links from one visitor in a short time. */
    public function isGuessing(?string $ip): bool
    {
        return $this->guesses->create($ip ?? 'unknown')->consume(0)->getRemainingTokens() < 1;
    }

    /**
     * Sets the password for the link; the link and every other link of the person stop working (UserManager::setPassword).
     *
     * @throws ContentException 410 when the link does not work (any more), 422 for a weak password or a repeat that differs
     */
    public function complete(string $token, string $password, string $repeat, ?string $ip = null): User
    {
        $link = $this->find($token, $ip) ?? throw new ContentException(I18n::t('http.link_gone'), 410, ['code' => 'link_gone']);
        $user = $link->user;
        if (User::BLOCKED === $user->status) {
            throw new ContentException(I18n::t('http.link_gone'), 410, ['code' => 'link_gone']);
        }
        UserManager::checkPasswordPolicy($password);
        if ($password !== $repeat) {
            throw ContentException::invalid(I18n::t('http.passwords_differ'), ['repeat' => I18n::t('http.passwords_differ')]);
        }
        $this->manager->setPassword($user, $password); // burns every link of the person, this one too

        return $user;
    }

    public static function hash(string $token): string
    {
        return \hash('sha256', $token);
    }

    private static function words(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $hours = (int) \round(($to->getTimestamp() - $from->getTimestamp()) / 3600);
        if ($hours <= 1) {
            return I18n::t('http.duration_hours', ['count' => 1]);
        }
        if ($hours < 48) {
            return I18n::t('http.duration_hours', ['count' => $hours]);
        }

        return I18n::t('http.duration_days', ['count' => (int) \round($hours / 24)]);
    }
}

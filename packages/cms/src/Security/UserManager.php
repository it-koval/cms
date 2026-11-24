<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\PasswordResetRepository;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Creating users and setting passwords (itk:install, the users screen). */
final class UserManager
{
    /** The shortest password a person chooses for himself (profile, itk:user:password); a generated one-time password is longer. */
    public const MIN_PASSWORD_LENGTH = 10;

    public const MAX_PASSWORD_LENGTH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordResetRepository $links,
    ) {
    }

    public function create(string $email, string $name, string $roleKey = Role::EDITOR, #[\SensitiveParameter] ?string $password = null, string $status = User::ACTIVE): User
    {
        $email = \mb_strtolower(\trim($email));
        $details = [];
        if ('' === \trim($name)) {
            $details['name'] = I18n::t('http.name_required');
        }
        if (!\preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $details['email'] = I18n::t('' === $email ? 'http.email_required' : 'http.email_typo');
        } elseif (null !== $this->users->findOneByEmail($email)) {
            $details['email'] = I18n::t('http.email_taken');
        }
        if (null === $this->roles->find($roleKey)) {
            $details['roleId'] = I18n::t('http.role_required');
        }
        if (null !== $password) {
            try {
                self::checkPasswordPolicy($password);
            } catch (ContentException $e) {
                $details['password'] = $e->getMessage();
            }
        }
        if ([] !== $details) {
            throw ContentException::invalid((string) \reset($details), $details);
        }
        $user = new User($email, \trim($name), $roleKey);
        $user->status = $status;
        if (null !== $password) {
            $user->passwordHash = $this->hasher->hashPassword($user, $password);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @throws ContentException when the password is too short or too long to accept */
    public static function checkPasswordPolicy(#[\SensitiveParameter] string $password, string $field = 'password'): void
    {
        $length = \mb_strlen($password);
        $message = $length < self::MIN_PASSWORD_LENGTH
            ? I18n::t('http.password_min', ['length' => self::MIN_PASSWORD_LENGTH])
            : ($length > self::MAX_PASSWORD_LENGTH ? I18n::t('http.password_max', ['length' => self::MAX_PASSWORD_LENGTH]) : null);
        if (null !== $message) {
            throw ContentException::invalid($message, [$field => $message]);
        }
    }

    /**
     * Every way of setting a password goes through here (profile, an administrator, the console, a link of a letter). The unused links of the person (reset, invitation) die with the old
     * password: nobody who got an old letter can set another one afterwards.
     */
    public function setPassword(User $user, #[\SensitiveParameter] string $password): void
    {
        self::checkPasswordPolicy($password);
        $user->passwordHash = $this->hasher->hashPassword($user, $password);
        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->flush();
            $this->links->burnAllOf($user->id, new \DateTimeImmutable());
        });
    }
}

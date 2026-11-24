<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InstallPasswordFileTest extends KernelTestCase
{
    #[Test]
    #[DataProvider('passwordFileLengths')]
    public function installChecksThePasswordPolicyForAFile(int $length, ?string $error): void
    {
        $kernel = self::bootKernel();
        $install = new CommandTester((new Application($kernel))->find('itk:install'));
        $users = self::getContainer()->get(UserRepository::class);
        $email = 'f1-install-' . \bin2hex(\random_bytes(8)) . '@example.test';
        $password = \substr(\bin2hex(\random_bytes($length)), 0, $length);
        $file = \tempnam(__DIR__, '.f1-install-');
        self::assertIsString($file);

        try {
            \file_put_contents($file, $password . "\r\n");
            $result = $install->execute(['--no-home' => true, '--email' => $email, '--password-file' => $file], ['interactive' => false]);
            self::assertStringNotContainsString($password, $install->getDisplay());
            $user = $users->findOneByEmail($email);
            if (null !== $error) {
                $limit = 'http.password_min' === $error ? UserManager::MIN_PASSWORD_LENGTH : UserManager::MAX_PASSWORD_LENGTH;
                self::assertSame(1, $result);
                self::assertStringContainsString(I18n::t($error, ['length' => $limit]), $install->getDisplay());
                self::assertNull($user);

                return;
            }

            self::assertSame(0, $result, $install->getDisplay());
            self::assertNotNull($user);
            self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password));
        } finally {
            \unlink($file);
            if (null !== ($user = $users->findOneByEmail($email))) {
                $em = self::getContainer()->get(EntityManagerInterface::class);
                $em->remove($user);
                $em->flush();
            }
        }
    }

    /** @return iterable<string, array{int, ?string}> */
    public static function passwordFileLengths(): iterable
    {
        yield 'below minimum' => [UserManager::MIN_PASSWORD_LENGTH - 1, 'http.password_min'];
        yield 'at minimum' => [UserManager::MIN_PASSWORD_LENGTH, null];
        yield 'long password' => [32, null];
        yield 'above maximum' => [UserManager::MAX_PASSWORD_LENGTH + 1, 'http.password_max'];
    }
}

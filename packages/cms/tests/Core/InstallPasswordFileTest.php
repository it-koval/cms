<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InstallPasswordFileTest extends KernelTestCase
{
    #[Test]
    public function installReadsTheFirstAdministratorPasswordFromAFile(): void
    {
        $kernel = self::bootKernel();
        $install = new CommandTester((new Application($kernel))->find('itk:install'));
        $users = self::getContainer()->get(UserRepository::class);
        $email = 'f1-install-' . \bin2hex(\random_bytes(8)) . '@example.test';
        $password = \bin2hex(\random_bytes(16));
        $file = \tempnam(__DIR__, '.f1-install-');
        self::assertIsString($file);

        try {
            \file_put_contents($file, $password . "\r\n");
            self::assertSame(0, $install->execute(['--no-home' => true, '--email' => $email, '--password-file' => $file], ['interactive' => false]), $install->getDisplay());
            self::assertStringNotContainsString($password, $install->getDisplay());
            $user = $users->findOneByEmail($email);
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
}

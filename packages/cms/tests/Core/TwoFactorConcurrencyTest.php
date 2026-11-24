<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\TwoFactor\Totp;
use Itk\Cms\Security\TwoFactor\TwoFactorService;
use Itk\Cms\Security\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class TwoFactorConcurrencyTest extends KernelTestCase
{
    /** @var list<Process> */
    private array $workers = [];
    private ?string $directory = null;
    private ?string $userId = null;

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                $worker->stop(0.2);
            }
            if (null !== $this->directory) {
                (new Filesystem())->remove($this->directory);
            }
            if (null !== $this->userId) {
                $em = self::getContainer()->get(EntityManagerInterface::class);
                $em->clear();
                $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\User u WHERE u.id = :id')->setParameter('id', $this->userId)->execute();
                self::getContainer()->get('limiter.itk_two_factor')->create($this->userId)->reset();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testConcurrentCodeAttemptsShareTheAtomicLimitAndCanResumeAfterTheLock(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factor = self::getContainer()->get(TwoFactorService::class);
        $user = self::getContainer()->get(UserManager::class)->create('test-f1-race-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Concurrency fixture', 'editor', 'innocuous-fixture-password');
        $this->userId = $user->id;
        $this->directory = self::$kernel->getProjectDir() . '/var/2fa-race-' . \bin2hex(\random_bytes(6));
        (new Filesystem())->mkdir($this->directory);

        $secret = $factor->startSetup($user, 'Fixture')['secret'];
        $codes = $factor->enable($user, Totp::code($secret, \time()));
        foreach (['a', 'b'] as $id) {
            $process = new Process([\PHP_BINARY, __DIR__ . '/Fixture/two-factor-worker.php', $user->id, $this->directory, $id], timeout: 25);
            $this->workers[] = $process;
            $process->start();
        }
        $deadline = \microtime(true) + 15;
        while (!\is_file($this->directory . '/ready-a') || !\is_file($this->directory . '/ready-b')) {
            if (\microtime(true) >= $deadline) {
                self::fail('Both concurrent requests must reach the barrier.');
            }
            \usleep(10000);
        }
        \touch($this->directory . '/start');
        $statuses = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            \array_push($statuses, ...\json_decode($worker->getOutput(), true, flags: \JSON_THROW_ON_ERROR));
        }
        self::assertSame(2, \count(\array_filter($statuses, static fn (int $status): bool => 429 === $status)), 'The fifth and sixth attempts are blocked across both requests.');
        $em->clear();
        $user = $em->find(User::class, $this->userId);
        self::assertNotNull($user);
        self::assertGreaterThan(0, $factor->lockedFor($user));
        $em->getConnection()->executeStatement('UPDATE itk_user_2fa SET locked_until = ? WHERE user_id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $this->userId]);
        self::getContainer()->get('limiter.itk_two_factor')->create($this->userId)->reset();
        self::assertTrue($factor->verify($user, $codes[0]), 'A correct unused code works after the lock expires.');
    }

    public function testDatabaseLockDoesNotSpendTheNextRateLimitWindow(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factor = self::getContainer()->get(TwoFactorService::class);
        $user = self::getContainer()->get(UserManager::class)->create('test-f1-lock-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Lock fixture', 'editor', 'innocuous-fixture-password');
        $this->userId = $user->id;
        $secret = $factor->startSetup($user, 'Fixture')['secret'];
        $codes = $factor->enable($user, Totp::code($secret, \time()));
        $em->getConnection()->executeStatement('UPDATE itk_user_2fa SET locked_until = ? WHERE user_id = ?', [(new \DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s'), $user->id]);
        $limiter = self::getContainer()->get('limiter.itk_two_factor')->create($user->id);
        $limiter->reset();
        for ($i = 0; $i < 6; ++$i) {
            try {
                $factor->verify($user, 'fixture-wrong-code');
                self::fail('The database latch must reject attempts.');
            } catch (\Itk\Cms\Content\ContentException $error) {
                self::assertSame(429, $error->status());
            }
        }
        $em->getConnection()->executeStatement('UPDATE itk_user_2fa SET locked_until = ? WHERE user_id = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $user->id]);
        self::assertTrue($factor->verify($user, $codes[0]), 'After the latch expires, its rejected attempts have not exhausted the fresh limiter window.');
    }

    public function testConcurrentPasswordCompletionsCannotBothSucceed(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = self::getContainer()->get(UserManager::class)->create('test-f1-reset-race-' . \bin2hex(\random_bytes(6)) . '@example.com', 'Reset fixture', 'editor', 'innocuous-fixture-password');
        $this->userId = $user->id;
        $token = \bin2hex(\random_bytes(24));
        $em->persist(new \Itk\Cms\Entity\PasswordReset($user, \Itk\Cms\Security\PasswordRecovery::hash($token), 'reset', new \DateTimeImmutable('+1 hour')));
        $em->flush();
        $this->directory = self::$kernel->getProjectDir() . '/var/reset-race-' . \bin2hex(\random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
        \file_put_contents($this->directory . '/token', $token);
        foreach (['a', 'b'] as $id) {
            $process = new Process([\PHP_BINARY, __DIR__ . '/Fixture/password-reset-worker.php', $this->directory, $id], timeout: 25);
            $this->workers[] = $process;
            $process->start();
        }
        $deadline = \microtime(true) + 15;
        while (!\is_file($this->directory . '/ready-a') || !\is_file($this->directory . '/ready-b')) {
            if (\microtime(true) >= $deadline) {
                self::fail('Both reset requests must cache the usable link before the barrier.');
            }
            \usleep(10000);
        }
        \touch($this->directory . '/start');
        $statuses = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $statuses[] = (int) $worker->getOutput();
        }
        \sort($statuses);
        self::assertSame([200, 410], $statuses, 'Exactly one concurrent completion may consume a password link.');
    }
}

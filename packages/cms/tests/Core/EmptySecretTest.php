<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Health\AppSecretHealthCheck;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/** An empty APP_SECRET: itk:install and «Состояние сайта» say so, and a request does not keep a hash of the visitor's address made with an empty key. */
final class EmptySecretTest extends KernelTestCase
{
    /** The secret as a real site has it: framework.secret: '%env(APP_SECRET)%' (Symfony refuses a literal empty one at build time, but an empty variable gets through). */
    private const FROM_ENV = ['secret' => '%env(APP_SECRET)%'];

    /** @var list<int> */
    private array $requestIds = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', $options['framework'] ?? []);
    }

    protected function setUp(): void
    {
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = '';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_SECRET'], $_ENV['APP_SECRET']);
        if ([] !== $this->requestIds) {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            foreach ($this->requestIds as $id) {
                $em->createQuery('DELETE FROM Itk\Cms\Entity\StoredNotification n WHERE n.title = :t')->setParameter('t', 'Новая заявка №' . $id)->execute();
                $em->createQuery('DELETE FROM Itk\Cms\Entity\Request r WHERE r.id = :i')->setParameter('i', $id)->execute();
            }
        }
        parent::tearDown();
    }

    private function submit(): \Itk\Cms\Entity\Request
    {
        $r = self::getContainer()->get(RequestService::class)->submit('call', ['phone' => '+7(900)000-00-31'], ['ip' => '203.0.113.7']);
        $this->requestIds[] = (int) $r->id;

        return $r;
    }

    #[Test]
    public function aRequestKeepsAHashOfTheAddressWhenTheSecretIsSet(): void
    {
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'a-secret-of-this-test';
        self::bootKernel(['framework' => self::FROM_ENV]);

        $r = $this->submit();

        self::assertSame(\hash_hmac('sha256', '203.0.113.7', 'a-secret-of-this-test'), $r->ipHash);
    }

    #[Test]
    public function aRequestKeepsNoHashOfTheAddressWhenTheSecretIsEmpty(): void
    {
        self::bootKernel(['framework' => self::FROM_ENV]);

        $r = $this->submit();

        self::assertNull($r->ipHash, 'a hash made with an empty key could be guessed for every address of the internet');
        self::assertSame('+7(900)000-00-31', $r->phone, 'the request itself is taken');
    }

    #[Test]
    public function installWarnsAboutAnEmptySecret(): void
    {
        $kernel = self::bootKernel(['framework' => self::FROM_ENV]);
        $install = new CommandTester((new Application($kernel))->find('itk:install'));

        self::assertSame(0, $install->execute(['--no-home' => true], ['interactive' => false]), $install->getDisplay());

        self::assertStringContainsString('APP_SECRET пуст', $install->getDisplay());
    }

    #[Test]
    public function installIsQuietWhenTheSecretIsSet(): void
    {
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'a-secret-of-this-test';
        $kernel = self::bootKernel(['framework' => self::FROM_ENV]);
        $install = new CommandTester((new Application($kernel))->find('itk:install'));

        $install->execute(['--no-home' => true], ['interactive' => false]);

        self::assertStringNotContainsString('APP_SECRET', $install->getDisplay());
    }

    #[Test]
    public function theHealthScreenWarnsAboutAnEmptySecret(): void
    {
        foreach ([['', 'warn'], ['   ', 'warn'], ['0123456789abcdef', 'ok']] as [$secret, $status]) {
            $result = (new AppSecretHealthCheck($secret))->check();

            self::assertSame($status, $result->status, \var_export($secret, true));
            if ('warn' === $status) {
                self::assertStringContainsString('APP_SECRET пуст', $result->message);
            }
        }
        self::assertSame('APP_SECRET', (new AppSecretHealthCheck('x'))->name());
    }
}

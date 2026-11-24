<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Health\LivenessProbe;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** GET /healthz: открытый адрес для проверки живости, отвечает только ok / fail, без подробностей. */
final class HealthzTest extends WebTestCase
{
    private ?string $dir = null;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        if (null !== $this->dir) {
            @\chmod($this->dir, 0o755);
            @\rmdir($this->dir);
        }
        parent::tearDown();
    }

    #[Test]
    public function aHealthySiteAnswersOkToAnyone(): void
    {
        $client = static::createClient();

        $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ok'], \json_decode((string) $client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        $client->request('HEAD', '/healthz');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function aFolderThatCannotBeWrittenIsAFailWithoutDetails(): void
    {
        $this->dir = \sys_get_temp_dir() . '/itk-healthz-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o555);
        if (\is_writable($this->dir)) {
            self::markTestSkipped('the folder is writable for this user (root?)');
        }
        $client = static::createClient(['itk' => ['upload_dir' => $this->dir]]);

        $client->request('GET', '/healthz');

        self::assertResponseStatusCodeSame(503);
        $body = (string) $client->getResponse()->getContent();
        self::assertSame(['status' => 'fail'], \json_decode($body, true));
        self::assertStringNotContainsString($this->dir, $body);
    }

    #[Test]
    public function aDatabaseThatDoesNotAnswerIsAFail(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('executeQuery')->willThrowException(new \RuntimeException('connection refused'));

        self::assertFalse((new LivenessProbe($db, \sys_get_temp_dir()))->isAlive());
    }

    #[Test]
    public function aFolderThatDoesNotExistYetIsFineWhenItsParentIsWritable(): void
    {
        $db = $this->createStub(Connection::class);

        self::assertTrue((new LivenessProbe($db, \sys_get_temp_dir() . '/itk-not-there-yet/media'))->isAlive());
    }
}

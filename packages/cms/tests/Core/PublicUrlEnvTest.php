<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\KernelInterface;

/** itk_cms.public_url принимает %env()%: формат проверяется после подстановки, пустое значение — как будто опции нет. */
final class PublicUrlEnvTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], ['public_url' => $options['public_url']]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['ITK_TEST_PUBLIC_URL'], $_ENV['ITK_TEST_PUBLIC_URL']);
        parent::tearDown();
    }

    private function boot(string $value): PublicUrl
    {
        $_SERVER['ITK_TEST_PUBLIC_URL'] = $_ENV['ITK_TEST_PUBLIC_URL'] = $value;
        self::bootKernel(['public_url' => '%env(ITK_TEST_PUBLIC_URL)%']);

        return self::getContainer()->get(PublicUrl::class);
    }

    #[Test]
    public function anAddressFromTheEnvironmentIsUsed(): void
    {
        self::assertSame('https://example.org', $this->boot('https://example.org/')->base());
    }

    #[Test]
    public function aWrongAddressFromTheEnvironmentIsRefusedWhenItIsRead(): void
    {
        $urls = $this->boot('example.org');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('itk_cms.public_url');
        $urls->base();
    }

    #[Test]
    public function anEmptyVariableIsAsIfTheOptionWasNotSet(): void
    {
        $urls = $this->boot('');

        self::assertNull($urls->base(), 'no request, no «domain» setting: no trusted address, as without the option');
    }

    #[Test]
    public function aWrongLiteralIsStillRefusedWhenTheContainerIsBuilt(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        self::bootKernel(['public_url' => 'example.org']);
    }

    #[Test]
    public function aGoodLiteralWorksAsBefore(): void
    {
        self::bootKernel(['public_url' => 'https://example.com/']);

        self::assertSame('https://example.com', self::getContainer()->get(PublicUrl::class)->base());
    }
}

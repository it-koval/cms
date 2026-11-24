<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Schema\SchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class RemovedSchemaFeatureTest extends TestCase
{
    /** @return iterable<string, array{string, list<mixed>, class-string<\Throwable>, string}> */
    public static function unavailableCalls(): iterable
    {
        $legacyMessage = 'SchemaBuilder::feature() was removed in ITK CMS 0.3.0. Remove the call for "%s" from your schema (usually config/itk/schema.php). See https://github.com/it-koval/cms/blob/main/UPGRADE.md#030';
        yield 'enabled by default' => ['feature', ['ai'], InvalidConfigurationException::class, \sprintf($legacyMessage, 'ai')];
        yield 'explicitly disabled' => ['feature', ['ai', false], InvalidConfigurationException::class, \sprintf($legacyMessage, 'ai')];
        yield 'case-insensitive legacy call' => ['Feature', ['ai'], InvalidConfigurationException::class, \sprintf($legacyMessage, 'ai')];
        yield 'other former flag' => ['feature', ['custom', true], InvalidConfigurationException::class, \sprintf($legacyMessage, 'custom')];
        yield 'typo' => ['unknown', [], \Error::class, 'Call to undefined method Itk\\Cms\\Schema\\SchemaBuilder::unknown()'];
    }

    /**
     * @param list<mixed> $arguments
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('unavailableCalls')]
    public function unavailableSchemaMethodsReportTheCause(string $method, array $arguments, string $exception, string $message): void
    {
        $builder = new SchemaBuilder();
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $builder->{$method}(...$arguments);
    }
}

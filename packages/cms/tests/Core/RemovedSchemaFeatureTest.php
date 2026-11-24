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
        $legacyMessage = 'SchemaBuilder::feature() was removed in ITK CMS 0.3.0. Remove this call from config/itk/schema.php. See UPGRADE.md.';
        yield 'enabled by default' => ['feature', ['ai'], InvalidConfigurationException::class, $legacyMessage];
        yield 'explicitly disabled' => ['feature', ['ai', false], InvalidConfigurationException::class, $legacyMessage];
        yield 'other former flag' => ['feature', ['custom', true], InvalidConfigurationException::class, $legacyMessage];
        yield 'typo' => ['unknown', [], \BadMethodCallException::class, 'Unknown schema configuration method SchemaBuilder::unknown().'];
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
        self::assertFalse(\method_exists($builder, $method));

        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $builder->{$method}(...$arguments);
    }
}

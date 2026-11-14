<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\PhpHealthCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpHealthCheckTest extends TestCase
{
    /** @return iterable<string, array{string, bool, string}> */
    public static function versions(): iterable
    {
        yield 'unsupported PHP' => ['8.2.35', false, '8.3.36'];
        yield '8.3 vulnerable' => ['8.3.35', false, '8.3.36'];
        yield '8.3 fixed' => ['8.3.36', true, '8.3.36'];
        yield '8.4 vulnerable' => ['8.4.26', false, '8.4.27'];
        yield '8.4 fixed' => ['8.4.27', true, '8.4.27'];
        yield '8.5 vulnerable' => ['8.5.11', false, '8.5.12'];
        yield '8.5 fixed' => ['8.5.12', true, '8.5.12'];
        yield 'future patch' => ['8.4.28', true, '8.4.27'];
        yield '8.3 prerelease' => ['8.3.36RC1', false, '8.3.36'];
        yield '8.4 prerelease' => ['8.4.27RC1', false, '8.4.27'];
        yield '8.5 prerelease' => ['8.5.12RC1', false, '8.5.12'];
        yield '8.6 vulnerable prerelease' => ['8.6.0RC3', false, '8.6.0'];
        yield '8.6 fixed prerelease is not a production release' => ['8.6.0RC4', false, '8.6.0'];
        yield 'future branch' => ['8.6.0', true, '8.6.0'];
    }

    #[Test]
    #[DataProvider('versions')]
    public function theRuntimeMustIncludeTheSecurityFixesOfItsOwnBranch(string $version, bool $safe, string $minimum): void
    {
        $check = new PhpHealthCheck($version);
        $result = $check->check();

        self::assertSame('PHP', $check->name());
        self::assertSame($safe ? HealthResult::OK : HealthResult::WARN, $result->status);
        self::assertStringContainsString($version, $result->message);
        if (!$safe) {
            self::assertStringContainsString($minimum, $result->message);
            self::assertStringContainsString('CVE-2026-109618', $result->message);
            self::assertStringContainsString('CVE-2026-109619', $result->message);
        }
    }
}

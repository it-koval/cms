<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Analytics\AnalyticsAsset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalyticsAssetTest extends TestCase
{
    #[Test]
    public function aLongLivedAssetRefreshesItsVersionWhenTheFileMtimeChanges(): void
    {
        $file = \tempnam(\dirname(__DIR__) . '/App', 'analytics-');
        self::assertIsString($file);
        $mtime = 1700000000;
        $asset = new AnalyticsAsset(file: $file);

        try {
            \file_put_contents($file, 'first');
            \touch($file, $mtime);
            $first = \substr(\hash('sha256', 'first'), 0, 16);
            self::assertStringEndsWith('?v=' . $first, $asset->url());

            \file_put_contents($file, 'second');
            \touch($file, $mtime);
            self::assertSame($first, $asset->version(), 'an unchanged mtime keeps the cached hash');

            \touch($file, $mtime + 1);
            $second = \substr(\hash('sha256', 'second'), 0, 16);
            self::assertSame($second, $asset->version());
            self::assertStringEndsWith('?v=' . $second, $asset->url());

            \unlink($file);
            self::assertSame('0', $asset->version());
        } finally {
            if (\is_file($file)) {
                \unlink($file);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\PageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageAddressShapeTest extends TestCase
{
    #[Test]
    public function legacyLocalPathsRemainValidWithinTheUnicodeLengthLimit(): void
    {
        foreach (['/', '/test-llms-блог (1)', '/test//path/', '/' . \str_repeat('я', 254)] as $url) {
            self::assertNull(PageService::addressShapeProblem($url), $url);
        }
    }

    #[Test]
    public function unsafePathsAndAddressesOverTheLimitAreRejected(): void
    {
        foreach (['', 'relative', '//host/path', '/bad?query', '/bad#fragment', '/bad\\path', "/bad\tpath", "/bad\npath", "/bad\0path", "/bad\x7fpath", '/' . \str_repeat('я', 255)] as $url) {
            self::assertNotNull(PageService::addressShapeProblem($url), \json_encode($url, \JSON_THROW_ON_ERROR));
        }
    }
}

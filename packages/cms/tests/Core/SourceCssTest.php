<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\CriticalCss\Model\SourceCss;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Исходный CSS для crittr готовится в PHP: файлы по порядку, локальные @import на месте, относительные url() от корня, пустые правила убраны. */
final class SourceCssTest extends TestCase
{
    /** @param array<string, string> $files */
    private static function reader(array $files): \Closure
    {
        return static fn (string $path): ?string => $files[$path] ?? null;
    }

    #[Test]
    public function filesAreConcatenatedInConfigOrderAndAMissingOneIsNamed(): void
    {
        $read = self::reader(['/css/a.css' => 'a{x:1}', '/css/b.css' => 'b{y:2}']);

        self::assertSame("a{x:1}\nb{y:2}", SourceCss::prepare(['/css/a.css', '/css/b.css'], $read));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('/css/none.css');
        SourceCss::prepare(['/css/a.css', '/css/none.css'], $read);
    }

    #[Test]
    public function relativeUrlsAreRebasedToTheRootAndOthersLeftAlone(): void
    {
        $css = 'a{background:url(../img/a.png) url("./b.svg") url(\'c.woff2?v=1\') url(/d.png) url(data:image/png;base64,AA==) url(https://x/e.png) url(#f) url(//cdn/g.png)}';

        self::assertSame(
            'a{background:url(/img/a.png) url("/css/b.svg") url(\'/css/c.woff2?v=1\') url(/d.png) url(data:image/png;base64,AA==) url(https://x/e.png) url(#f) url(//cdn/g.png)}',
            SourceCss::rebaseUrls($css, '/css'),
        );
        self::assertSame('a{background:url(/img/a.png)}', SourceCss::rebaseUrls('a{background:url(img/a.png)}', ''));
    }

    #[Test]
    public function localImportsAreInlinedOneLevelDeepWithTheirMediaAndTheirOwnBase(): void
    {
        $read = self::reader([
            '/css/site.css' => '@import url("base/reset.css"); @import "print.css" print; @import url(https://fonts.example/f.css); a{x:1}',
            '/css/base/reset.css' => '@import "deeper.css"; *{margin:0;background:url(../../img/bg.png)}',
            '/css/base/deeper.css' => 'nope{z:1}',
            '/css/print.css' => 'p{color:black}',
        ]);

        $css = SourceCss::prepare(['/css/site.css'], $read);

        self::assertStringContainsString('*{margin:0;background:url(/img/bg.png)}', $css);
        self::assertStringContainsString('@media print{p{color:black}}', $css);
        self::assertStringContainsString('@import url(https://fonts.example/f.css);', $css, 'a remote import stays');
        self::assertStringNotContainsString('nope', $css, 'one level only');
        self::assertStringEndsWith('a{x:1}', $css);
    }

    #[Test]
    public function anImportCycleDoesNotLoop(): void
    {
        $read = self::reader(['/a.css' => '@import "b.css"; a{x:1}', '/b.css' => '@import "a.css"; b{y:2}']);

        self::assertStringEndsWith('b{y:2} a{x:1}', \trim(SourceCss::prepare(['/a.css'], $read)));
    }

    #[Test]
    public function emptyRulesAndCommentsAreDropped(): void
    {
        $read = self::reader(['/s.css' => "/* a{} */a{}\nb{x:1}\n@media (min-width:1px){c{}}\nd { }\ne{y:2}"]);

        self::assertSame("b{x:1}\ne{y:2}", \trim(SourceCss::prepare(['/s.css'], $read)));
    }

    #[Test]
    public function whatCrittrLosesIsNamed(): void
    {
        self::assertSame([], SourceCss::lossy('a{x:1}'));
        self::assertSame(['!important', '@layer'], SourceCss::lossy('@layer base; a{x:1 !important}'));
        self::assertSame([], SourceCss::lossy('.layered{x:1}'), '@layer as a word, not a rule');
    }
}

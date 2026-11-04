<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\CriticalCss\Model\ExtractedCss;
use Itk\Cms\CriticalCss\Model\ExtractionFailed;
use Itk\Cms\CriticalCss\Model\SourcePage;
use Itk\Cms\CriticalCss\Model\Viewport;
use Itk\Cms\CriticalCss\NodeCssExtractor;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Tests\App\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Сторона PHP в договоре с node/critical-css.mjs: задание уходит одним JSON на stdin, строки NDJSON читаются по мере появления,
 * страница без строки получает причину по коду выхода, зависший скрипт останавливается по сроку. Вместо node — tests/App/bin/fake-node.sh.
 */
final class NodeCssExtractorTest extends KernelTestCase
{
    /** @return list<Viewport> */
    private static function viewports(): array
    {
        return [new Viewport(390, 844, true), new Viewport(1366, 900)];
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ITK_FAKE_NODE']);
        parent::tearDown();
    }

    private function extractor(string $mode, int $timeout = 10, int $grace = 15): NodeCssExtractor
    {
        $_ENV['ITK_FAKE_NODE'] = $mode; // Process inherits $_ENV, not what a test puts into $_SERVER
        $c = self::getContainer();

        return new NodeCssExtractor(\dirname(__DIR__) . '/App/bin/fake-node.sh', '/opt/chrome', $timeout, $c->getParameter('kernel.project_dir'), $c->get(Stylesheets::class), $c->get('logger'), $grace);
    }

    /** @return array<string, ExtractedCss|ExtractionFailed> */
    private function measure(NodeCssExtractor $extractor, string ...$ids): array
    {
        $pages = \array_map(static fn (string $id): SourcePage => new SourcePage($id, '/' . $id, '<html>' . $id . '</html>'), $ids);

        return \iterator_to_array($extractor->extract('body{margin:0}', self::viewports(), $pages));
    }

    #[Test]
    public function everyPageGetsOneResultAndTheJobCarriesWhatTheScriptNeeds(): void
    {
        $results = $this->measure($this->extractor('ok'), 'a', 'b', 'c');

        self::assertSame(['a', 'b', 'c'], \array_keys($results));
        self::assertInstanceOf(ExtractedCss::class, $results['a']);
        self::assertSame(['x{a:1}', 'x{a:2}'], $results['a']->cssPerViewport);
        self::assertInstanceOf(ExtractionFailed::class, $results['b']);
        self::assertSame('page broke', $results['b']->reason);
        self::assertInstanceOf(ExtractionFailed::class, $results['c']);
        self::assertSame('no result', $results['c']->reason, 'the script ended without a line for it');

        $logger = self::getContainer()->get('logger');
        \assert($logger instanceof RecordingLogger);
        $stderr = \implode("\n", \array_map(static fn (array $r): string => (string) ($r['context']['stderr'] ?? ''), \array_filter($logger->records, static fn (array $r): bool => 'debug' === $r['level'])));
        $job = \json_decode((string) \strtok($stderr, "\n"), true);
        self::assertIsArray($job);
        self::assertSame(1, $job['v']);
        self::assertSame('/opt/chrome', $job['chrome']);
        self::assertSame(10 * 1000 * 3 * 2, $job['deadlineMs'], 'timeout × pages × viewports');
        self::assertSame(10_000, $job['timeoutMs']);
        self::assertSame('body{margin:0}', $job['css']);
        self::assertSame([['width' => 390, 'height' => 844, 'mobile' => true], ['width' => 1366, 'height' => 900, 'mobile' => false]], $job['viewports']);
        self::assertSame(['id' => 'a', 'path' => '/a', 'html' => '<html>a</html>'], $job['pages'][0]);
        self::assertStringEndsWith('/public', $job['publicDir']);
    }

    #[Test]
    public function aScriptThatCannotRunHereNamesTheReasonForEveryPage(): void
    {
        $results = $this->measure($this->extractor('unavailable'), 'a');

        self::assertInstanceOf(ExtractionFailed::class, $results['a']);
        self::assertSame('not installed: crittr is not in /site/node_modules (npm install crittr)', $results['a']->reason);
    }

    #[Test]
    public function aCrashIsAFailureWithTheExitCode(): void
    {
        $results = $this->measure($this->extractor('crash'), 'a');

        self::assertInstanceOf(ExtractionFailed::class, $results['a']);
        self::assertStringStartsWith('crashed (70)', $results['a']->reason);
    }

    #[Test]
    public function aHangingScriptIsStoppedAndThePagesDoneBeforeAreKept(): void
    {
        $started = \microtime(true);
        $results = $this->measure($this->extractor('hang', 1, 1), 'a', 'b');

        self::assertLessThan(15, \microtime(true) - $started, 'stopped at the deadline plus the grace, not at the end of sleep 60');
        self::assertInstanceOf(ExtractedCss::class, $results['a']);
        self::assertInstanceOf(ExtractionFailed::class, $results['b']);
        self::assertSame('timeout', $results['b']->reason);
    }

    #[Test]
    public function resultLinesAreParsedAtTheBoundary(): void
    {
        self::assertNull(NodeCssExtractor::parseLine('garbage', 2));
        self::assertNull(NodeCssExtractor::parseLine('{"ok":true}', 2));
        $short = NodeCssExtractor::parseLine('{"id":"a","ok":true,"css":["x"]}', 2);
        self::assertInstanceOf(ExtractionFailed::class, $short['result'] ?? null);
        self::assertSame('bad output', $short['result']->reason);
        $good = NodeCssExtractor::parseLine('{"id":"a","ok":true,"css":["x","y"]}', 2);
        self::assertInstanceOf(ExtractedCss::class, $good['result'] ?? null);
        $failed = NodeCssExtractor::parseLine('{"id":"a","ok":false}', 2);
        self::assertSame('unknown error', $failed['result']->reason ?? null);

        self::assertSame('timeout', NodeCssExtractor::exitReason(124, ''));
        self::assertSame('not installed: Node 22 or newer is needed', NodeCssExtractor::exitReason(69, "crittr log\nnot installed: Node 22 or newer is needed\n"));
        self::assertSame('crashed', NodeCssExtractor::exitReason(null, ''));
    }
}

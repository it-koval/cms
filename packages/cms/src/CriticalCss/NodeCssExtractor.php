<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Itk\Cms\CriticalCss\Model\ExtractedCss;
use Itk\Cms\CriticalCss\Model\ExtractionFailed;
use Itk\Cms\CriticalCss\Model\SourcePage;
use Itk\Cms\CriticalCss\Model\Viewport;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs node/critical-css.mjs of the bundle once per call: one Node process, one Chrome, every page and viewport of the call.
 *
 * Wire contract (version 1), the whole of it:
 *   argv    node <bundle>/node/critical-css.mjs
 *   cwd     %kernel.project_dir% (the script resolves crittr from <project>/node_modules, never from its own path: the bundle may be a symlink)
 *   stdin   one JSON document:
 *           {"v":1, "projectDir":…, "publicDir":…, "chrome":…|null, "deadlineMs":…, "timeoutMs":…, "css":"<prepared source CSS>",
 *            "viewports":[{"width":390,"height":844,"mobile":true},…], "keepSelectors":[…], "removeSelectors":[…],
 *            "pages":[{"id":…, "path":"/about", "html":"<!DOCTYPE…"}]}
 *   stdout  NDJSON, one line per page as it finishes: {"id":…, "ok":true, "css":["…","…"]} | {"id":…, "ok":false, "error":"…"}
 *   stderr  crittr's log: kept as a 4 KB tail, logged at debug, quoted in the reason when the process fails
 *   exit    0    every page got a line (single pages may have failed)
 *           64   bad input (a bug here: the reason says so)
 *           69   not installable here: Node < 22, crittr or puppeteer not found, Chrome did not start (the reason is the last line of stderr)
 *           124  the script's own deadline (it closed Chrome first)
 *           other, or a signal: crashed
 *   Pages without a line after the exit get ExtractionFailed with the reason of the exit code.
 *
 * Timeout: deadlineMs = timeout × pages × viewports; the script closes Chrome and exits 124 at deadlineMs, this process stops the script at
 * deadlineMs + 15 s (SIGTERM, then SIGKILL by Process::stop). Chrome is launched with pipe: true, so it goes when the script dies hard
 * (checked on the stand: no chrome survives a SIGKILL of node). Nothing secret is passed: no headers, no cookies, no URL of the live site.
 *
 * @internal
 */
#[AsAlias(CssExtractor::class)]
final class NodeCssExtractor implements CssExtractor
{
    private const STDERR_TAIL = 4096;

    public function __construct(
        #[Autowire(param: 'itk_cms.critical_css.node')]
        private readonly string $node,
        #[Autowire(param: 'itk_cms.critical_css.chrome')]
        private readonly ?string $chrome,
        #[Autowire(param: 'itk_cms.critical_css.timeout')]
        private readonly int $timeoutPerRun,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly Stylesheets $stylesheets,
        private readonly LoggerInterface $logger,
        /** seconds the script gets after its own deadline before it is stopped */
        private readonly int $grace = 15,
    ) {
    }

    public function extract(string $sourceCss, array $viewports, array $pages): iterable
    {
        $deadlineMs = $this->timeoutPerRun * 1000 * \count($pages) * \max(1, \count($viewports));
        $input = (string) \json_encode([
            'v' => 1,
            'projectDir' => $this->projectDir,
            'publicDir' => $this->stylesheets->publicDir(),
            'chrome' => $this->chrome,
            'deadlineMs' => $deadlineMs,
            'timeoutMs' => $this->timeoutPerRun * 1000,
            'css' => $sourceCss,
            'viewports' => \array_map(static fn (Viewport $v): array => $v->toArray(), $viewports),
            'keepSelectors' => $this->stylesheets->keepSelectors(),
            'removeSelectors' => $this->stylesheets->removeSelectors(),
            'pages' => \array_map(static fn (SourcePage $p): array => ['id' => $p->id, 'path' => $p->path, 'html' => $p->html], $pages),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);

        $pending = [];
        foreach ($pages as $page) {
            $pending[$page->id] = true;
        }
        $process = new Process([$this->node, self::script()], $this->projectDir, null, $input, $deadlineMs / 1000 + $this->grace);
        $buffer = '';
        $stderr = '';
        $reason = null;
        try {
            $process->start();
            foreach ($process as $type => $chunk) {
                if (Process::ERR === $type) {
                    $stderr = \substr($stderr . $chunk, -self::STDERR_TAIL);
                    continue;
                }
                $buffer .= $chunk;
                while (false !== ($nl = \strpos($buffer, "\n"))) {
                    $line = \substr($buffer, 0, $nl);
                    $buffer = \substr($buffer, $nl + 1);
                    $parsed = self::parseLine($line, \count($viewports));
                    if (null === $parsed || !isset($pending[$parsed['id']])) {
                        continue;
                    }
                    unset($pending[$parsed['id']]);
                    yield $parsed['id'] => $parsed['result'];
                }
            }
        } catch (ProcessTimedOutException) {
            $process->stop(5);
            $reason = 'timeout';
        } catch (\Throwable $e) {
            $reason = 'node could not be started: ' . $e->getMessage();
        }
        if ('' !== \trim($stderr)) {
            $this->logger->debug('critical-css.mjs: {stderr}', ['stderr' => $stderr]);
        }
        $reason ??= self::exitReason($process->getExitCode(), $stderr);
        foreach (\array_keys($pending) as $id) {
            yield $id => new ExtractionFailed($reason);
        }
    }

    /**
     * One NDJSON line of the script; null for anything that is not a result line (the boundary: a malformed line is skipped, not trusted).
     *
     * @return array{id: string, result: ExtractedCss|ExtractionFailed}|null
     */
    public static function parseLine(string $line, int $viewportCount): ?array
    {
        $data = \json_decode($line, true);
        if (!\is_array($data) || !isset($data['id']) || !\is_string($data['id'])) {
            return null;
        }
        if (true === ($data['ok'] ?? null)) {
            $css = $data['css'] ?? null;
            $valid = \is_array($css) && \array_is_list($css) && \count($css) === $viewportCount && \count(\array_filter($css, 'is_string')) === $viewportCount;

            return ['id' => $data['id'], 'result' => $valid ? new ExtractedCss($css) : new ExtractionFailed('bad output')];
        }

        return ['id' => $data['id'], 'result' => new ExtractionFailed(\is_string($data['error'] ?? null) && '' !== $data['error'] ? $data['error'] : 'unknown error')];
    }

    /** Why pages got no line: from the exit code and the last line of stderr (the script prints its reason and exits; crittr's log comes before it). */
    public static function exitReason(?int $code, string $stderrTail): string
    {
        $lines = \array_values(\array_filter(\array_map('trim', \explode("\n", $stderrTail)), static fn (string $l): bool => '' !== $l));
        $last = [] === $lines ? '' : $lines[\count($lines) - 1];

        return match ($code) {
            0 => 'no result',
            64 => 'bad input: ' . $last,
            69 => '' !== $last ? $last : 'not installed',
            124 => 'timeout',
            null => 'crashed',
            default => 'crashed (' . $code . '): ' . $last,
        };
    }

    public static function script(): string
    {
        return \dirname(__DIR__, 2) . '/node/critical-css.mjs';
    }
}

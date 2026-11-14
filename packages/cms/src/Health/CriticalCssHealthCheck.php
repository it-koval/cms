<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Stylesheets;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * «Критический CSS» in «Разработчику → Состояние сайта». Quick by contract: `node --version`, a few stats and two small queries, never Chrome.
 *   off                                                     ok    "off"
 *   a configured stylesheet is not under public/            fail  (every page is served with the plain link)
 *   node not found, or older than 22                        fail
 *   <project>/node_modules/crittr missing                   fail  ("npm install crittr")
 *   failed rows                                             warn  count and the newest reason; itk:critical-css:generate retries them
 *   messages waiting in `critical_css` for over STALE s     warn  "is a worker consuming critical_css?"
 *   otherwise                                               ok    "current for N of M live page versions", plus "K have the previous one" for ready rows of another stamp
 * "Current" compares the stamp of ready rows (the container build and Stylesheets::stamp()) with today's, which needs no snapshot. A row of another stamp
 * is still shown to visitors while the worker makes the new one. Staleness of the snapshot alone (a republish) is not counted: the publish listener
 * has already asked the worker, and the basis of every page would need every snapshot, too slow for this screen.
 *
 * @internal
 */
final class CriticalCssHealthCheck implements HealthCheckInterface
{
    private const NODE_MAJOR = 22;
    /** a message waiting in the queue for longer than this many seconds means that nobody takes it */
    private const STALE = 300;

    public function __construct(
        private readonly Stylesheets $stylesheets,
        private readonly Connection $db,
        private readonly CriticalCssService $critical,
        #[Autowire(param: 'itk_cms.critical_css.node')]
        private readonly string $node,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return I18n::t('ops.health_critical_css');
    }

    public function check(): HealthResult
    {
        if (!$this->stylesheets->enabled) {
            return HealthResult::ok(I18n::t('ops.health_critical_css_off'));
        }
        $missing = $this->stylesheets->missing();
        if ([] !== $missing) {
            return HealthResult::fail(I18n::t('ops.health_critical_css_no_stylesheet', ['path' => $missing[0]]));
        }
        $version = $this->nodeVersion();
        if (null === $version || (int) \ltrim($version, 'v') < self::NODE_MAJOR) {
            return HealthResult::fail(I18n::t('ops.health_critical_css_no_node', ['node' => $this->node, 'version' => $version ?? '-']));
        }
        if (!\is_file($this->projectDir . '/node_modules/crittr/package.json')) {
            return HealthResult::fail(I18n::t('ops.health_critical_css_no_crittr'));
        }

        $counts = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS live,
                    COUNT(*) FILTER (WHERE css.status = :ready AND css.stamp = :stamp) AS current,
                    COUNT(*) FILTER (WHERE css.status = :ready) AS ready,
                    COUNT(*) FILTER (WHERE css.status = :failed) AS failed
             FROM itk_page_translation t LEFT JOIN itk_critical_css css ON css.translation_id = t.id
             WHERE t.status = :published',
            ['stamp' => $this->critical->stamp(), 'ready' => CriticalCss::READY, 'failed' => CriticalCss::FAILED, 'published' => PageTranslation::PUBLISHED],
        );
        $failed = (int) ($counts['failed'] ?? 0);
        if ($failed > 0) {
            $reason = (string) $this->db->fetchOne(
                'SELECT css.error FROM itk_critical_css css JOIN itk_page_translation t ON t.id = css.translation_id
                 WHERE css.status = ? AND t.status = ? ORDER BY css.generated_at DESC LIMIT 1',
                [CriticalCss::FAILED, PageTranslation::PUBLISHED],
            );

            return HealthResult::warn(I18n::t('ops.health_critical_css_failed', ['count' => $failed, 'reason' => MessengerMonitor::scrub($reason)]));
        }
        $waiting = $this->waiting();
        if (null !== $waiting) {
            return HealthResult::warn(I18n::t('ops.health_critical_css_waiting', ['count' => $waiting['count'], 'minutes' => (int) \floor($waiting['age'] / 60)]));
        }
        $live = (int) ($counts['live'] ?? 0);
        $current = (int) ($counts['current'] ?? 0);
        $ready = (int) ($counts['ready'] ?? 0);
        if ($ready > $current) {
            return HealthResult::ok(I18n::t('ops.health_critical_css_previous', ['current' => $current, 'live' => $live, 'previous' => $ready - $current]));
        }

        return HealthResult::ok(I18n::t('ops.health_critical_css_ok', ['current' => $current, 'live' => $live]));
    }

    /** "v22.12.0" of the configured binary, null when it cannot be run. */
    private function nodeVersion(): ?string
    {
        try {
            $process = new Process([$this->node, '--version'], $this->projectDir, null, null, 5);
            $process->run();

            return $process->isSuccessful() ? \trim($process->getOutput()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Messages of the database transport `critical_css` that have waited longer than STALE (the doctrine transport keeps them in messenger_messages;
     * another transport cannot say how old its messages are, and then nothing is reported).
     *
     * @return array{count: int, age: int}|null
     */
    private function waiting(): ?array
    {
        try {
            if (!$this->db->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return null;
            }
            $row = $this->db->fetchAssociative("SELECT COUNT(*) AS n, MIN(available_at) AS oldest FROM messenger_messages WHERE queue_name = 'critical_css' AND delivered_at IS NULL AND available_at <= (now() AT TIME ZONE 'UTC')");
        } catch (\Throwable) {
            return null;
        }
        if (false === $row || 0 === (int) $row['n'] || !\is_string($row['oldest'])) {
            return null;
        }
        $age = \time() - (new \DateTimeImmutable($row['oldest'], new \DateTimeZone('UTC')))->getTimestamp();

        return $age > self::STALE ? ['count' => (int) $row['n'], 'age' => $age] : null;
    }
}

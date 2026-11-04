<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
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
 *   otherwise                                               ok    "N of M live page versions have critical CSS"
 * "Have" counts rows with status ready, not their freshness: the basis of every page would need every snapshot, too slow for this screen.
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

        $counts = ['ready' => 0, 'failed' => 0];
        foreach ($this->db->fetchAllKeyValue('SELECT status, COUNT(*) FROM itk_critical_css GROUP BY status') as $status => $n) {
            $counts[(string) $status] = (int) $n;
        }
        if ($counts['failed'] > 0) {
            $reason = (string) $this->db->fetchOne('SELECT error FROM itk_critical_css WHERE status = ? ORDER BY generated_at DESC LIMIT 1', [CriticalCss::FAILED]);

            return HealthResult::warn(I18n::t('ops.health_critical_css_failed', ['count' => $counts['failed'], 'reason' => MessengerMonitor::scrub($reason)]));
        }
        $waiting = $this->waiting();
        if (null !== $waiting) {
            return HealthResult::warn(I18n::t('ops.health_critical_css_waiting', ['count' => $waiting['count'], 'minutes' => (int) \floor($waiting['age'] / 60)]));
        }
        $live = (int) $this->db->fetchOne('SELECT COUNT(*) FROM itk_page_translation WHERE status = ?', [PageTranslation::PUBLISHED]);

        return HealthResult::ok(I18n::t('ops.health_critical_css_ok', ['ready' => $counts['ready'], 'live' => $live]));
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
            $row = $this->db->fetchAssociative("SELECT COUNT(*) AS n, MIN(available_at) AS oldest FROM messenger_messages WHERE queue_name = 'critical_css' AND delivered_at IS NULL AND available_at <= UTC_TIMESTAMP()");
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

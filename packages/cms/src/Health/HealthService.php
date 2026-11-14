<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\Entity\Request as SiteRequest;
use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\RequestRepository;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Everything «Разработчику → Состояние сайта» shows (GET /admin/api/health):
 *   { status 'ok'|'warn'|'fail' (a check may also be 'off': not connected, it does not count), generatedAt,
 *     messenger { queues [{name, waiting (null when the transport does not work), error?}], failed { available, total, items [{id, type, label, error, errorClass, retries, failedAt, queue}], error? } },
 *     requests { failed (number), items [{id, dt, form, error}] },
 *     schedules { available, items [{schedule, trigger, next}] },
 *     disk [{key, label, free, total, usedPct, status}],
 *     versions { php, symfony, itk, itkReference },
 *     updates { enabled, current, latest, hasUpdate, checkedAt, message },
 *     checks [{name, status, message}] }
 * `status` is the worst of the checks (a check that is `off` ranks like `ok`), the disks, and «warn» when something sits in the failed queue or a request was not delivered.
 */
final class HealthService
{
    private const FAILED_REQUESTS = 20;
    private const DISK_WARN = 2 * 1024 ** 3; // free bytes: a percentage says nothing on a disk of half a terabyte
    private const DISK_FAIL = 512 * 1024 ** 2;

    /**
     * @param iterable<HealthCheckInterface> $checks
     * @param iterable<object> $schedules providers of Symfony Scheduler schedules (none without the component)
     */
    public function __construct(
        private readonly MessengerMonitor $messenger,
        private readonly UpdateChecker $updates,
        private readonly RequestRepository $requestRepo,
        private readonly RequestService $requests,
        private readonly Schema $schema,
        private readonly Registry $extensions,
        #[Autowire('%itk_cms.upload_dir%')]
        private readonly string $uploadDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[AutowireIterator('itk.health_check')]
        private readonly iterable $checks = [],
        #[AutowireIterator('scheduler.schedule_provider', indexAttribute: 'name')]
        private readonly iterable $schedules = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function report(bool $refreshUpdates = false): array
    {
        $messenger = ['queues' => $this->messenger->queues(), 'failed' => $this->messenger->failed()];
        $requests = $this->failedRequests();
        $disk = $this->disk();
        $checks = $this->runChecks();
        $versions = $this->versions();

        $status = HealthResult::OK;
        foreach ([...\array_column($disk, 'status'), ...\array_column($checks, 'status')] as $s) {
            $status = HealthResult::worst($status, $s);
        }
        if ($messenger['failed']['total'] > 0 || $requests['failed'] > 0 || isset($messenger['failed']['error']) || [] !== \array_filter($messenger['queues'], static fn (array $q): bool => isset($q['error']))) {
            $status = HealthResult::worst($status, HealthResult::WARN);
        }

        return [
            'status' => $status,
            'generatedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'messenger' => $messenger,
            'requests' => $requests,
            'schedules' => $this->schedules(),
            'disk' => $disk,
            'versions' => $versions,
            'updates' => $this->updates->check($versions['itk'], $refreshUpdates),
            'checks' => $checks,
        ];
    }

    /**
     * Sends a request whose delivery failed to its messengers again, right now: to the one asked for, else to each that failed.
     *
     * @return array{delivery: string, error: string, channels: list<array{channel: string, state: string, error: ?string}>}
     */
    public function resendRequest(string $id, ?string $channel = null): array
    {
        $r = $this->requests->resend($id, $channel);

        return [
            'delivery' => $r->delivery,
            'error' => MessengerMonitor::scrub((string) $r->deliveryError),
            'channels' => \array_map(static fn (array $c): array => [...$c, 'error' => null === $c['error'] ? null : MessengerMonitor::scrub($c['error'])], $this->requests->channels($r)),
        ];
    }

    /** Asks the update source again, without waiting for the 12 hours to pass. @return array<string, mixed> */
    public function refreshUpdates(): array
    {
        return $this->updates->check($this->versions()['itk'], true);
    }

    /**
     * @return array{failed: int, items: list<array<string, mixed>>}
     */
    private function failedRequests(): array
    {
        $items = [];
        foreach ($this->requestRepo->findBy(['delivery' => SiteRequest::DELIVERY_FAILED], ['id' => 'DESC'], self::FAILED_REQUESTS) as $r) {
            $items[] = [
                'id' => $r->id,
                'dt' => $r->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'form' => $this->schema->form($r->form)?->formTitle() ?? $r->form, // no name or phone: this screen is for developers
                'error' => MessengerMonitor::scrub((string) $r->deliveryError),
            ];
        }

        return ['failed' => \max($this->requests->counts()['failed'], \count($items)), 'items' => $items];
    }

    /**
     * @return array{available: bool, items: list<array{schedule: string, trigger: string, next: ?string}>}
     */
    private function schedules(): array
    {
        $items = [];
        $available = false;
        $now = new \DateTimeImmutable();
        foreach ($this->schedules as $name => $provider) {
            $available = true;
            try {
                foreach ($provider->getSchedule()->getRecurringMessages() as $message) {
                    $trigger = $message->getTrigger();
                    $items[] = ['schedule' => (string) $name, 'trigger' => (string) $trigger, 'next' => $trigger->getNextRunDate($now)?->format(\DATE_ATOM)];
                }
            } catch (\Throwable) {
                $items[] = ['schedule' => (string) $name, 'trigger' => I18n::t('ops.health_schedule_unreadable'), 'next' => null];
            }
        }

        return ['available' => $available, 'items' => $items];
    }

    /**
     * @return list<array{key: string, label: string, free: ?int, total: ?int, usedPct: ?int, status: string}>
     */
    private function disk(): array
    {
        $out = [];
        foreach ([['uploads', I18n::t('ops.health_disk_uploads'), $this->uploadDir], ['var', I18n::t('ops.health_disk_var'), $this->projectDir . '/var']] as [$key, $label, $path]) {
            $dir = $path;
            while (!\is_dir($dir) && \dirname($dir) !== $dir) { // the folder may not exist yet: its disk is the disk of the nearest parent
                $dir = \dirname($dir);
            }
            $free = @\disk_free_space($dir);
            $total = @\disk_total_space($dir);
            if (false === $free || false === $total || $total <= 0) {
                $out[] = ['key' => $key, 'label' => $label, 'free' => null, 'total' => null, 'usedPct' => null, 'status' => HealthResult::WARN];

                continue;
            }
            $out[] = [
                'key' => $key, 'label' => $label, 'free' => (int) $free, 'total' => (int) $total, 'usedPct' => (int) \round(($total - $free) / $total * 100),
                'status' => $free < self::DISK_FAIL ? HealthResult::FAIL : ($free < self::DISK_WARN ? HealthResult::WARN : HealthResult::OK),
            ];
        }

        return $out;
    }

    /**
     * @return array{php: string, symfony: string, itk: ?string, itkReference: ?string}
     */
    private function versions(): array
    {
        $itk = $reference = null;
        $file = $this->projectDir . '/vendor/composer/installed.json';
        $json = \is_file($file) ? \json_decode((string) \file_get_contents($file), true) : null;
        foreach (\is_array($json) ? ($json['packages'] ?? $json) : [] as $package) {
            if (\is_array($package) && 'itk/cms' === ($package['name'] ?? null)) {
                $itk = isset($package['version']) ? (string) $package['version'] : null;
                $reference = isset($package['dist']['reference']) ? \substr((string) $package['dist']['reference'], 0, 7) : null;

                break;
            }
        }

        return ['php' => \PHP_VERSION, 'symfony' => Kernel::VERSION, 'itk' => $itk, 'itkReference' => $reference];
    }

    /**
     * Checks of the core and of the extensions: those tagged `itk.health_check`, and the classes an extension names in Registry::healthChecks().
     *
     * @return list<array{name: string, status: string, message: string}>
     */
    private function runChecks(): array
    {
        $list = [];
        foreach ($this->checks as $check) {
            $list[$check::class] = $check;
        }
        $broken = [];
        foreach ($this->extensions->healthCheckClasses() as $class) {
            if (isset($list[$class])) {
                continue;
            }
            if (\class_exists($class) && \is_subclass_of($class, HealthCheckInterface::class)) {
                try {
                    $list[$class] = new $class();
                } catch (\Throwable) {
                    $broken[] = ['name' => $class, 'status' => HealthResult::FAIL, 'message' => I18n::t('ops.health_check_not_created')];
                }
            } else {
                $broken[] = ['name' => $class, 'status' => HealthResult::FAIL, 'message' => I18n::t('ops.health_check_missing')];
            }
        }

        $out = [];
        foreach ($list as $check) {
            try {
                $result = $check->check();
                $out[] = ['name' => $check->name(), 'status' => $result->status, 'message' => $result->message];
            } catch (\Throwable $e) {
                $out[] = ['name' => $check->name(), 'status' => HealthResult::FAIL, 'message' => I18n::t('ops.health_check_failed', ['error' => MessengerMonitor::scrub($e->getMessage())])];
            }
        }

        return [...$out, ...$broken];
    }
}

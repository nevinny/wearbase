<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Repository\ScheduledCommandRepository;
use App\Repository\WardrobeItemDraftRepository;

final class WardrobeIngestHealth
{
    public const SCHEDULER_SLA_SECONDS = 600;
    public const PENDING_SLA_SECONDS = 900;

    public function __construct(
        private readonly WardrobeItemDraftRepository $drafts,
        private readonly ScheduledCommandRepository $scheduledCommands,
        private readonly string $storageDir,
    ) {}

    /** @return array<string, bool|int|string|array<int, string>|null> */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $drafts = $this->drafts->operationalSnapshot($now);
        $scheduler = $this->scheduledCommands->findWardrobeIngestWorker();
        $lastRun = $scheduler?->getLastRunAt();
        $lastExitCode = $scheduler?->getLastExitCode();
        $storageExists = is_dir($this->storageDir);
        $storageWritable = $storageExists && is_writable($this->storageDir);
        // Vich создаёт каталог лениво при первой загрузке фото, а deploy-prune может удалить
        // пустой каталог: отсутствие само по себе не авария, если родитель позволяет создать его.
        $storageCreatable = $storageExists ? $storageWritable : $this->isAncestorWritable($this->storageDir);
        $oldestPendingAge = $drafts['oldestPendingAt'] === null ? null : max(0, $now->getTimestamp() - $drafts['oldestPendingAt']->getTimestamp());
        $lastRunAge = $lastRun === null ? null : max(0, $now->getTimestamp() - $lastRun->getTimestamp());
        $criticalReasons = [];
        if (!$storageCreatable) {
            $criticalReasons[] = 'storage_not_writable';
        }
        if ($scheduler === null) {
            $criticalReasons[] = 'scheduler_missing';
        } elseif (!$scheduler->isEnabled()) {
            $criticalReasons[] = 'scheduler_disabled';
        } elseif ($scheduler->getEnvironment() !== 'prod') {
            $criticalReasons[] = 'scheduler_wrong_environment';
        } elseif ($lastRun === null) {
            $criticalReasons[] = 'scheduler_never_run';
        } elseif ($lastRunAge > self::SCHEDULER_SLA_SECONDS) {
            $criticalReasons[] = 'scheduler_stale';
        }
        if ($lastExitCode !== null && $lastExitCode !== 0) {
            $criticalReasons[] = 'scheduler_last_run_failed';
        }
        if ($oldestPendingAge !== null && $oldestPendingAge > self::PENDING_SLA_SECONDS) {
            $criticalReasons[] = 'oldest_pending_sla_exceeded';
        }
        $warningReasons = [];
        foreach (['expiredLeases' => 'expired_leases', 'retrying' => 'retrying', 'failed' => 'failed'] as $metric => $reason) {
            if ($drafts[$metric] > 0) {
                $warningReasons[] = $reason;
            }
        }

        $status = $criticalReasons !== [] ? 'critical' : ($warningReasons !== [] ? 'warning' : 'ok');

        return [
            'status' => $status,
            'critical_reasons' => $criticalReasons,
            'warning_reasons' => $warningReasons,
            'pending' => $drafts['pending'],
            'oldest_pending_at' => $drafts['oldestPendingAt']?->format(DATE_ATOM),
            'oldest_pending_age_seconds' => $oldestPendingAge,
            'oldest_pending_sla_seconds' => self::PENDING_SLA_SECONDS,
            'expired_leases' => $drafts['expiredLeases'],
            'failed' => $drafts['failed'],
            'retrying' => $drafts['retrying'],
            'storage_path' => $this->storageDir,
            'storage_exists' => $storageExists,
            'storage_writable' => $storageWritable,
            'storage_creatable' => $storageCreatable,
            'storage_usage_bytes' => $drafts['storageBytes'],
            'storage_free_bytes' => $storageExists ? $this->diskFreeSpace($this->storageDir) : null,
            'scheduler_configured' => $scheduler !== null,
            'scheduler_enabled' => $scheduler?->isEnabled(),
            'scheduler_environment' => $scheduler?->getEnvironment(),
            'scheduler_last_run_at' => $lastRun?->format(DATE_ATOM),
            'scheduler_last_run_age_seconds' => $lastRunAge,
            'scheduler_sla_seconds' => self::SCHEDULER_SLA_SECONDS,
            'scheduler_last_exit_code' => $lastExitCode,
            'scheduler_last_success_at' => $lastExitCode === 0 ? $lastRun?->format(DATE_ATOM) : null,
            'scheduler_last_success_known' => $lastExitCode === 0,
        ];
    }

    private function diskFreeSpace(string $path): ?int
    {
        $bytes = @disk_free_space($path);

        return $bytes === false ? null : (int) $bytes;
    }

    private function isAncestorWritable(string $path): bool
    {
        $parent = dirname($path);
        while (!is_dir($parent)) {
            $parent = dirname($parent);
        }

        return is_writable($parent);
    }
}

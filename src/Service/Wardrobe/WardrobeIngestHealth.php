<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Repository\ScheduledCommandRepository;
use App\Repository\WardrobeItemDraftRepository;

final class WardrobeIngestHealth
{
    public function __construct(
        private readonly WardrobeItemDraftRepository $drafts,
        private readonly ScheduledCommandRepository $scheduledCommands,
        private readonly string $storageDir,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function snapshot(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $drafts = $this->drafts->operationalSnapshot($now);
        $scheduler = $this->scheduledCommands->findWardrobeIngestWorker();
        $lastRun = $scheduler?->getLastRunAt();
        $lastExitCode = $scheduler?->getLastExitCode();
        $storageExists = is_dir($this->storageDir);
        $storageWritable = $storageExists && is_writable($this->storageDir);

        $status = 'ok';
        if (!$storageWritable || ($lastExitCode !== null && $lastExitCode !== 0)) {
            $status = 'critical';
        } elseif ($drafts['expiredLeases'] > 0
            || $drafts['retrying'] > 0
            || $drafts['failed'] > 0
            || $scheduler === null
            || !$scheduler->isEnabled()
            || $lastRun === null
        ) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'pending' => $drafts['pending'],
            'oldest_pending_at' => $drafts['oldestPendingAt']?->format(DATE_ATOM),
            'oldest_pending_age_seconds' => $drafts['oldestPendingAt'] === null ? null : max(0, $now->getTimestamp() - $drafts['oldestPendingAt']->getTimestamp()),
            'expired_leases' => $drafts['expiredLeases'],
            'failed' => $drafts['failed'],
            'retrying' => $drafts['retrying'],
            'storage_path' => $this->storageDir,
            'storage_exists' => $storageExists,
            'storage_writable' => $storageWritable,
            'storage_usage_bytes' => $drafts['storageBytes'],
            'storage_free_bytes' => $storageExists ? $this->diskFreeSpace($this->storageDir) : null,
            'scheduler_configured' => $scheduler !== null,
            'scheduler_enabled' => $scheduler?->isEnabled(),
            'scheduler_last_run_at' => $lastRun?->format(DATE_ATOM),
            'scheduler_last_run_age_seconds' => $lastRun === null ? null : max(0, $now->getTimestamp() - $lastRun->getTimestamp()),
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
}

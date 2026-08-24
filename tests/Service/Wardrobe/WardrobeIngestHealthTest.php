<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\ScheduledCommand;
use App\Repository\ScheduledCommandRepository;
use App\Repository\WardrobeItemDraftRepository;
use App\Service\Wardrobe\WardrobeIngestHealth;
use PHPUnit\Framework\TestCase;

final class WardrobeIngestHealthTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir().'/wardrobe-health-'.bin2hex(random_bytes(4));
        mkdir($this->storageDir);
    }

    protected function tearDown(): void
    {
        @rmdir($this->storageDir);
    }

    public function testSnapshotReportsQueueStorageAndSuccessfulLastRun(): void
    {
        $now = new \DateTimeImmutable('2026-08-24T12:00:00+03:00');
        $drafts = $this->createMock(WardrobeItemDraftRepository::class);
        $drafts->method('operationalSnapshot')->with($now)->willReturn([
            'pending' => 2,
            'oldestPendingAt' => $now->modify('-5 minutes'),
            'expiredLeases' => 0,
            'failed' => 0,
            'retrying' => 0,
            'storageBytes' => 4096,
        ]);
        $job = (new ScheduledCommand())
            ->setName('ingest')->setCommand('app:wardrobe:ingest-drafts --no-debug')->setSchedule('*/2 * * * *')
            ->setLastRunAt(\DateTime::createFromImmutable($now->modify('-1 minute')))
            ->setLastExitCode(0);
        $scheduled = $this->createMock(ScheduledCommandRepository::class);
        $scheduled->method('findWardrobeIngestWorker')->willReturn($job);

        $snapshot = (new WardrobeIngestHealth($drafts, $scheduled, $this->storageDir))->snapshot($now);

        self::assertSame('ok', $snapshot['status']);
        self::assertSame(300, $snapshot['oldest_pending_age_seconds']);
        self::assertSame(4096, $snapshot['storage_usage_bytes']);
        self::assertTrue($snapshot['storage_writable']);
        self::assertTrue($snapshot['scheduler_last_success_known']);
        self::assertSame(60, $snapshot['scheduler_last_run_age_seconds']);
        self::assertSame('2026-08-24T11:59:00+03:00', $snapshot['scheduler_last_success_at']);
    }

    public function testFailedLastRunAndMissingStorageAreCriticalWithoutInventingPreviousSuccess(): void
    {
        $drafts = $this->createMock(WardrobeItemDraftRepository::class);
        $drafts->method('operationalSnapshot')->willReturn([
            'pending' => 1,
            'oldestPendingAt' => null,
            'expiredLeases' => 1,
            'failed' => 3,
            'retrying' => 1,
            'storageBytes' => 0,
        ]);
        $job = (new ScheduledCommand())
            ->setName('ingest')->setCommand('app:wardrobe:ingest-drafts --no-debug')->setSchedule('*/2 * * * *')
            ->setLastRunAt(new \DateTime('2026-08-24T11:00:00+03:00'))
            ->setLastExitCode(1);
        $scheduled = $this->createMock(ScheduledCommandRepository::class);
        $scheduled->method('findWardrobeIngestWorker')->willReturn($job);

        $snapshot = (new WardrobeIngestHealth($drafts, $scheduled, $this->storageDir.'/missing'))->snapshot();

        self::assertSame('critical', $snapshot['status']);
        self::assertFalse($snapshot['storage_exists']);
        self::assertFalse($snapshot['storage_writable']);
        self::assertFalse($snapshot['scheduler_last_success_known']);
        self::assertNull($snapshot['scheduler_last_success_at']);
    }
}

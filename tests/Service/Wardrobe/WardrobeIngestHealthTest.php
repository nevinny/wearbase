<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\ScheduledCommand;
use App\Repository\ScheduledCommandRepository;
use App\Repository\WardrobeItemDraftRepository;
use App\Service\Wardrobe\WardrobeIngestHealth;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->setEnvironment('prod')->setName('ingest')->setCommand('app:wardrobe:ingest-drafts --no-debug')->setSchedule('*/2 * * * *')
            ->setLastRunAt(\DateTime::createFromImmutable($now->modify('-1 minute')))
            ->setLastExitCode(0);
        $scheduled = $this->createMock(ScheduledCommandRepository::class);
        $scheduled->method('findWardrobeIngestWorker')->willReturn($job);

        $snapshot = (new WardrobeIngestHealth($drafts, $scheduled, $this->storageDir))->snapshot($now);

        self::assertSame('ok', $snapshot['status']);
        self::assertSame([], $snapshot['critical_reasons']);
        self::assertSame(300, $snapshot['oldest_pending_age_seconds']);
        self::assertSame(4096, $snapshot['storage_usage_bytes']);
        self::assertTrue($snapshot['storage_writable']);
        self::assertTrue($snapshot['storage_creatable']);
        self::assertTrue($snapshot['scheduler_last_success_known']);
        self::assertSame(60, $snapshot['scheduler_last_run_age_seconds']);
        self::assertSame('2026-08-24T11:59:00+03:00', $snapshot['scheduler_last_success_at']);
    }

    public function testFailedLastRunAndCreatableMissingStorageStayCriticalOnlyFromScheduler(): void
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
            ->setEnvironment('prod')->setName('ingest')->setCommand('app:wardrobe:ingest-drafts --no-debug')->setSchedule('*/2 * * * *')
            ->setLastRunAt(new \DateTime('2026-08-24T11:00:00+03:00'))
            ->setLastExitCode(1);
        $scheduled = $this->createMock(ScheduledCommandRepository::class);
        $scheduled->method('findWardrobeIngestWorker')->willReturn($job);

        $snapshot = (new WardrobeIngestHealth($drafts, $scheduled, $this->storageDir.'/missing'))->snapshot();

        self::assertSame('critical', $snapshot['status']);
        self::assertFalse($snapshot['storage_exists']);
        self::assertFalse($snapshot['storage_writable']);
        // Vich создаёт каталог при первой загрузке — отсутствие не критично.
        self::assertTrue($snapshot['storage_creatable']);
        self::assertNotContains('storage_not_writable', $snapshot['critical_reasons']);
        self::assertFalse($snapshot['scheduler_last_success_known']);
        self::assertNull($snapshot['scheduler_last_success_at']);
        self::assertContains('scheduler_last_run_failed', $snapshot['critical_reasons']);
    }

    public function testUncreatableStorageDirectoryStaysCritical(): void
    {
        $lockedParent = sys_get_temp_dir().'/wardrobe-health-ro-'.bin2hex(random_bytes(4));
        mkdir($lockedParent, 0555);
        try {
            $drafts = $this->createStub(WardrobeItemDraftRepository::class);
            $drafts->method('operationalSnapshot')->willReturn([
                'pending' => 0, 'oldestPendingAt' => null,
                'expiredLeases' => 0, 'failed' => 0, 'retrying' => 0, 'storageBytes' => 0,
            ]);
            $scheduled = $this->createStub(ScheduledCommandRepository::class);
            $scheduled->method('findWardrobeIngestWorker')->willReturn(null);

            $snapshot = (new WardrobeIngestHealth($drafts, $scheduled, $lockedParent.'/missing'))->snapshot();

            self::assertSame('critical', $snapshot['status']);
            self::assertFalse($snapshot['storage_creatable']);
            self::assertContains('storage_not_writable', $snapshot['critical_reasons']);
        } finally {
            chmod($lockedParent, 0755);
            @rmdir($lockedParent);
        }
    }

    #[DataProvider('criticalSchedulerProvider')]
    public function testSchedulerAndQueueSlaFailuresAreCritical(?ScheduledCommand $job, int $pendingAge, string $reason): void
    {
        $now = new \DateTimeImmutable('2026-08-24T12:00:00+03:00');
        $drafts = $this->createMock(WardrobeItemDraftRepository::class);
        $drafts->method('operationalSnapshot')->willReturn([
            'pending' => 1,
            'oldestPendingAt' => $now->modify(sprintf('-%d seconds', $pendingAge)),
            'expiredLeases' => 0,
            'failed' => 0,
            'retrying' => 0,
            'storageBytes' => 1,
        ]);
        $scheduled = $this->createMock(ScheduledCommandRepository::class);
        $scheduled->method('findWardrobeIngestWorker')->willReturn($job);

        $snapshot = (new WardrobeIngestHealth($drafts, $scheduled, $this->storageDir))->snapshot($now);

        self::assertSame('critical', $snapshot['status']);
        self::assertContains($reason, $snapshot['critical_reasons']);
    }

    /** @return iterable<string, array{?ScheduledCommand,int,string}> */
    public static function criticalSchedulerProvider(): iterable
    {
        $healthy = static fn (): ScheduledCommand => (new ScheduledCommand())->setEnvironment('prod')
            ->setName('ingest')->setCommand('app:wardrobe:ingest-drafts --no-debug')->setSchedule('*/2 * * * *')
            ->setLastRunAt(new \DateTime('2026-08-24T11:59:00+03:00'))->setLastExitCode(0);

        yield 'missing scheduler' => [null, 60, 'scheduler_missing'];
        yield 'disabled scheduler' => [$healthy()->setEnabled(false), 60, 'scheduler_disabled'];
        yield 'never run' => [$healthy()->setLastRunAt(null)->setLastExitCode(null), 60, 'scheduler_never_run'];
        yield 'stale scheduler' => [$healthy()->setLastRunAt(new \DateTime('2026-08-24T11:49:00+03:00')), 60, 'scheduler_stale'];
        yield 'wrong environment' => [$healthy()->setEnvironment('dev'), 60, 'scheduler_wrong_environment'];
        yield 'oldest pending over SLA' => [$healthy(), WardrobeIngestHealth::PENDING_SLA_SECONDS + 1, 'oldest_pending_sla_exceeded'];
    }
}

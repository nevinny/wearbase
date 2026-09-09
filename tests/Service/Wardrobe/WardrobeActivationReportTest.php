<?php

declare(strict_types=1);

namespace App\Tests\Service\Wardrobe;

use App\Entity\WardrobeActivationEvent;
use App\Service\Wardrobe\WardrobeActivationReport;
use PHPUnit\Framework\TestCase;

final class WardrobeActivationReportTest extends TestCase
{
    public function testBuildsFunnelCohortsAndManualInputEvidence(): void
    {
        $rows = [
            $this->row(1, WardrobeActivationEvent::ONBOARDING_STARTED, '2026-08-01 10:00:00'),
            $this->row(2, WardrobeActivationEvent::ONBOARDING_STARTED, '2026-08-01 11:00:00'),
            $this->row(1, WardrobeActivationEvent::FIRST_ITEM_ADDED, '2026-08-01 10:02:00'),
            $this->row(1, WardrobeActivationEvent::FIRST_OUTFIT_CREATED, '2026-08-01 10:12:00'),
            $this->row(1, WardrobeActivationEvent::REPEAT_WEAR_RECORDED, '2026-08-02 10:00:00'),
            $this->row(1, WardrobeActivationEvent::BATCH_RECOGNITION_STARTED, '2026-08-01 10:00:10'),
            $this->row(1, WardrobeActivationEvent::BATCH_RECOGNITION_COMPLETED, '2026-08-01 10:01:00'),
            $this->row(1, WardrobeActivationEvent::DRAFT_ACCEPTED, '2026-08-01 10:02:00', ['source' => 'ai', 'durationBucket' => '1_5m', 'correction' => false, 'autofillAccepted' => true]),
            $this->row(2, WardrobeActivationEvent::DRAFT_ACCEPTED, '2026-08-01 11:04:00', ['source' => 'manual_correction', 'durationBucket' => '1_5m', 'correction' => true, 'autofillAccepted' => false]),
        ];

        $report = (new WardrobeActivationReport())->build($rows);

        self::assertSame(2, $report['subjectsStarted']);
        self::assertSame(['count' => 1, 'total' => 2, 'rate' => 0.5, 'averageSeconds' => 120], $report['milestones']['firstItem']);
        self::assertSame(1.0, $report['batch']['rate']);
        self::assertSame(0.5, $report['manualInput']['correction']['rate']);
        self::assertSame(0.5, $report['manualInput']['autofillAccepted']['rate']);
        self::assertSame(['started' => 2, 'firstItem' => 1, 'firstOutfit' => 1, 'repeatWear' => 1], $report['cohorts']['2026-08-01']);
    }

    /** @param array<string,bool|string> $metadata */
    private function row(int $subject, string $event, string $at, array $metadata = []): array
    {
        return ['profileSubjectId' => $subject, 'eventType' => $event, 'metadata' => $metadata, 'occurredAt' => $at];
    }
}

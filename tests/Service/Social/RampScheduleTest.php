<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\RampSchedule;
use PHPUnit\Framework\TestCase;

class RampScheduleTest extends TestCase
{
    private function at(string $date): \DateTime
    {
        return new \DateTime($date, new \DateTimeZone('Europe/Moscow'));
    }

    public function testNoLaunchDateHoldsStart(): void
    {
        $ramp = new RampSchedule();
        self::assertSame(3, $ramp->dailyTarget(3, 14, null));
        self::assertSame(3, $ramp->dailyTarget(3, 14, ''));
    }

    public function testWeekZeroIsStart(): void
    {
        $ramp = new RampSchedule();
        // тот же день старта → неделя 0 → старт
        self::assertSame(3, $ramp->dailyTarget(3, 14, '2026-06-01', $this->at('2026-06-01 12:00')));
    }

    public function testRampGrowsByWeek(): void
    {
        $ramp = new RampSchedule();
        // start=3, +12.5%/нед: w1=3.375→3, w2=3.8→4, w4≈4.8→5
        self::assertSame(3, $ramp->dailyTarget(3, 14, '2026-06-01', $this->at('2026-06-08 12:00')));  // w1
        self::assertSame(4, $ramp->dailyTarget(3, 14, '2026-06-01', $this->at('2026-06-15 12:00')));  // w2
        self::assertSame(5, $ramp->dailyTarget(3, 14, '2026-06-01', $this->at('2026-06-29 12:00')));  // w4
    }

    public function testCapsAtMax(): void
    {
        $ramp = new RampSchedule();
        // далёкая дата → упор в потолок
        self::assertSame(14, $ramp->dailyTarget(3, 14, '2025-01-01', $this->at('2026-06-01 12:00')));
    }

    public function testCapNeverBelowStart(): void
    {
        $ramp = new RampSchedule();
        // cap < start → нормализуется к start
        self::assertSame(5, $ramp->dailyTarget(5, 2, null));
    }
}

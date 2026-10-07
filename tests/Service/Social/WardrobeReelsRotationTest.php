<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\WardrobeReelsRotation;
use PHPUnit\Framework\TestCase;

class WardrobeReelsRotationTest extends TestCase
{
    /** @return list<string> */
    private static function days(int $n, string $from = '2030-01-01'): array
    {
        $d = new \DateTimeImmutable($from);
        return array_map(static fn (int $i) => $d->modify("+$i days")->format('Y-m-d'), range(0, $n - 1));
    }

    private static function c(string $id, string $segment): array
    {
        return ['id' => $id, 'segment' => $segment];
    }

    public function testSameTemplateNotMoreOftenThanEvery14Days(): void
    {
        $cands = [self::c('t01-a-v1', 'С1'), self::c('t01-a-v2', 'С1'), self::c('t01-a-v3', 'С1'), self::c('t02-b-v1', 'С2')];
        $plan = (new WardrobeReelsRotation())->plan($cands, self::days(40), []);

        $days = array_keys(array_filter($plan, static fn (string $id) => str_starts_with($id, 't01-')));
        sort($days);
        self::assertCount(3, $days);
        for ($i = 1; $i < count($days); $i++) {
            self::assertGreaterThanOrEqual(14, (new \DateTimeImmutable($days[$i - 1]))->diff(new \DateTimeImmutable($days[$i]))->days);
        }
        // Меньший вариант идёт раньше.
        self::assertSame('t01-a-v1', $plan[$days[0]]);
    }

    public function testNeighbourDaysAlternateSegments(): void
    {
        $cands = [];
        foreach (['С1', 'С2', 'С3'] as $k => $seg) {
            foreach ([1, 2] as $v) {
                $cands[] = self::c(sprintf('t%02d-x-v1', $k * 2 + $v), $seg);
            }
        }
        $plan = (new WardrobeReelsRotation())->plan($cands, self::days(6), []);
        $seg = array_column($cands, 'segment', 'id');
        $list = array_values($plan);
        self::assertCount(6, $list);
        for ($i = 1; $i < 6; $i++) {
            self::assertNotSame($seg[$list[$i - 1]], $seg[$list[$i]]);
        }
    }

    public function testExistingQueueCountsForGapAndHolesAreFilledLater(): void
    {
        $occupied = [['day' => '2030-01-05', 'id' => 't01-a-v1', 'segment' => 'С1']];
        $free = array_values(array_diff(self::days(30), ['2030-01-05']));
        $plan = (new WardrobeReelsRotation())->plan([self::c('t01-a-v2', 'С1')], $free, $occupied);

        // Слот ближе 14 дней к уже стоящему v1 того же шаблона не годится ни до, ни после него.
        self::assertCount(1, $plan);
        $day = array_key_first($plan);
        self::assertGreaterThanOrEqual(14, (new \DateTimeImmutable('2030-01-05'))->diff(new \DateTimeImmutable($day))->days);
    }

    public function testNoCandidateLeavesGap(): void
    {
        $plan = (new WardrobeReelsRotation())->plan([self::c('t01-a-v1', 'С1'), self::c('t01-a-v2', 'С1')], self::days(5), []);
        self::assertCount(1, $plan);
    }

    public function testOnlyFreeDaysAreUsedAndEveryCandidateAtMostOnce(): void
    {
        $cands = [self::c('t01-a-v1', 'С1'), self::c('t02-b-v1', 'С2'), self::c('t03-c-v1', 'С3')];
        $free = ['2030-02-03', '2030-02-07'];
        $plan = (new WardrobeReelsRotation())->plan($cands, $free, []);
        self::assertCount(2, $plan);
        self::assertSame($free, array_keys($plan));
        self::assertCount(2, array_unique($plan));
    }
}

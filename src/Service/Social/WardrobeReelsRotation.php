<?php

declare(strict_types=1);

namespace App\Service\Social;

/**
 * Раскладка готовых гардеробных рилсов по свободным дням окна.
 * Правила: один шаблон (tNN) не чаще раза в MIN_GAP_DAYS (варианты v1..v4 одного шаблона разносятся тем же правилом),
 * соседние дни — разные сегменты, при прочих равных раньше идёт меньший вариант. Если подходящего ролика нет — день остаётся
 * дырой и заполнится при следующем запуске, когда появятся новые ролики.
 */
final class WardrobeReelsRotation
{
    public const MIN_GAP_DAYS = 14;

    /**
     * @param list<array{id: string, segment: string}> $candidates готовые ролики, которых нет ни в очереди, ни среди опубликованных
     * @param list<string>                              $freeDays  свободные дни Y-m-d
     * @param list<array{day: string, id: string, segment: string}> $occupied уже стоящие/опубликованные ролики (в т.ч. вне окна)
     *
     * @return array<string, string> день => id ролика
     */
    public function plan(array $candidates, array $freeDays, array $occupied): array
    {
        sort($freeDays);
        $placed = $occupied;
        $left = array_values($candidates);
        $plan = [];

        foreach ($freeDays as $day) {
            $best = null;
            $bestKey = null;
            foreach ($left as $i => $c) {
                $template = self::template($c['id']);
                if ($this->hasTemplateNear($placed, $template, $day)) {
                    continue;
                }
                $key = [
                    $this->neighbourSegmentClashes($placed, $c['segment'], $day),
                    -min(7, $this->nearestSameSegmentDistance($placed, $c['segment'], $day)),
                    self::variant($c['id']),
                    $c['id'],
                ];
                if ($bestKey === null || $key < $bestKey) {
                    $best = $i;
                    $bestKey = $key;
                }
            }
            if ($best === null) {
                continue;
            }
            $plan[$day] = $left[$best]['id'];
            $placed[] = ['day' => $day, 'id' => $left[$best]['id'], 'segment' => $left[$best]['segment']];
            array_splice($left, $best, 1);
        }

        return $plan;
    }

    public static function template(string $id): string
    {
        return preg_match('/^(t\d{2})-/', $id, $m) === 1 ? $m[1] : $id;
    }

    public static function variant(string $id): int
    {
        return preg_match('/-v(\d+)$/', $id, $m) === 1 ? (int) $m[1] : 1;
    }

    private static function diffDays(string $a, string $b): int
    {
        return (int) abs((new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->days);
    }

    private function hasTemplateNear(array $placed, string $template, string $day): bool
    {
        foreach ($placed as $p) {
            if (self::template($p['id']) === $template && self::diffDays($p['day'], $day) < self::MIN_GAP_DAYS) {
                return true;
            }
        }

        return false;
    }

    private function neighbourSegmentClashes(array $placed, string $segment, string $day): int
    {
        $n = 0;
        foreach ($placed as $p) {
            if ($p['segment'] === $segment && self::diffDays($p['day'], $day) === 1) {
                ++$n;
            }
        }

        return $n;
    }

    private function nearestSameSegmentDistance(array $placed, string $segment, string $day): int
    {
        $min = PHP_INT_MAX;
        foreach ($placed as $p) {
            if ($p['segment'] === $segment) {
                $min = min($min, self::diffDays($p['day'], $day));
            }
        }

        return $min;
    }
}

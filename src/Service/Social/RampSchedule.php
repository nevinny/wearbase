<?php

declare(strict_types=1);

namespace App\Service\Social;

/**
 * Чистая формула ramp-up дневного потолка публикаций: имитирует ручной вывод контента
 * (резкий скачок объёма вредит охвату/доверию площадки). Вынесена из publish-tick ради
 * тестируемости. Зеркало логики app:brand:publish-tick.
 *
 *   T(w) = min(cap, round(start * (1 + GROWTH)^w)),  w — полных недель с launchDate.
 */
final class RampSchedule
{
    private const GROWTH = 0.125; // +12.5%/нед
    private const TZ = 'Europe/Moscow';

    public function dailyTarget(int $start, int $cap, ?string $launchDate, ?\DateTimeInterface $now = null): int
    {
        $start = max(1, $start);
        $cap = max($start, $cap);

        if ($launchDate === null || trim($launchDate) === '') {
            return $start; // нет даты старта — держим стартовый темп
        }

        $tz = new \DateTimeZone(self::TZ);
        $now ??= new \DateTime('now', $tz);
        $weeks = max(0, (int) floor(($now->getTimestamp() - (new \DateTime($launchDate, $tz))->getTimestamp()) / (7 * 86400)));

        return (int) min($cap, round($start * (1 + self::GROWTH) ** $weeks));
    }
}

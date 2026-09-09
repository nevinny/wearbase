<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrandContentRevision;

/**
 * Судья closed-loop эксперимента: сравнивает метрику ДО/ПОСЛЕ ревизии контента
 * и выдаёт вердикт win/loss/neutral/not_indexed. Вынесено из
 * EvaluateExperimentsCommand::judge() — второй потребитель (городские хабы,
 * см. SeoCityHubCommand/CityHubRevision) не должен держать копию порогов,
 * они бы неизбежно разъехались с бренд-веткой.
 *
 * Дерево решений и все пороги — 1:1 из бренд-ветки (см. docs/rag_pipeline.md §10):
 * не в индексе → not_indexed; в индексе, но образца мало → win только если страница
 * вошла в индекс именно после ревизии, иначе not_indexed; иначе порог (rel 20% + пол).
 */
class ClosedLoopJudge
{
    private const MIN_SAMPLE     = 10;   // меньше показов → судить нельзя (вероятно не в индексе)
    private const DELTA_REL      = 0.2;  // относит. порог срабатывания (шум)
    private const DELTA_ABS_CLK  = 2;    // абсолютный пол по кликам
    private const DELTA_ABS_IMPR = 10;   // абсолютный пол по показам

    public function verdict(
        int $imprBefore,
        int $clicksBefore,
        bool $indexedBefore,
        int $imprAfter,
        int $clicksAfter,
        bool $indexedNow,
    ): string {
        // 1. Можно ли вообще судить? Не в индексе → контент не виноват, поиск не дал шанс.
        if (!$indexedNow) {
            return BrandContentRevision::VERDICT_NOT_INDEXED;
        }
        $newlyIndexed = !$indexedBefore;
        if ($imprAfter < self::MIN_SAMPLE && $imprBefore < self::MIN_SAMPLE) {
            // Трафика не было и нет, но страница ВОШЛА в индекс после ревизии — это и есть
            // главный исход эксперимента (in_search Яндекса — живой критерий, покрытие
            // Google заморожено). Иначе (была в индексе, показов нет) — судить нечем.
            // Если baseline ≥ MIN_SAMPLE — НЕ сюда: обвал показов в ~0 должен судиться
            // порогами ниже как loss, а не проскакивать в win по факту входа в индекс.
            return $newlyIndexed ? BrandContentRevision::VERDICT_WIN : BrandContentRevision::VERDICT_NOT_INDEXED;
        }

        $imprThr = max(self::DELTA_ABS_IMPR, (int) round($imprBefore * self::DELTA_REL));
        $clkThr  = max(self::DELTA_ABS_CLK, (int) round($clicksBefore * self::DELTA_REL));

        $clicksDropped = $clicksAfter < $clicksBefore - $clkThr;
        $imprDropped   = $imprAfter   < $imprBefore - $imprThr;
        $imprUp        = $imprAfter   > $imprBefore + $imprThr;

        if ($clicksDropped || $imprDropped) {
            return BrandContentRevision::VERDICT_LOSS;
        }
        if (!$clicksDropped && ($imprUp || $newlyIndexed)) {
            return BrandContentRevision::VERDICT_WIN;
        }

        return BrandContentRevision::VERDICT_NEUTRAL;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * Шингл-гейт плагиата (_docs/news-sources-tos.md §2.1): доля 5-словных
 * шинглов рерайта, встретившихся в исходнике, должна быть ≤10%.
 * Запас легальности нулевой (запрет воспроизведения у всех MVP-источников),
 * поэтому порог жёстче плановых 10–15%.
 */
final class ShingleGate
{
    public const N = 5;
    public const THRESHOLD = 0.10;

    /**
     * Доля шинглов рерайта, совпавших с исходником (containment по rewrite).
     *
     * @param int<1, max> $n
     */
    public function overlap(string $source, string $rewrite, int $n = self::N): float
    {
        $sourceShingles = $this->shingles($source, $n);
        if ($sourceShingles === []) {
            return 0.0;
        }

        $rewriteShingles = array_values(array_unique($this->rawShingles($rewrite, $n)));
        if ($rewriteShingles === []) {
            return 0.0;
        }

        $hits = 0;
        foreach ($rewriteShingles as $shingle) {
            if (isset($sourceShingles[$shingle])) {
                ++$hits;
            }
        }

        return $hits / count($rewriteShingles);
    }

    public function passes(float $score): bool
    {
        return $score <= self::THRESHOLD;
    }

    /** @return array<string, true> уникальные шинглы → true */
    private function shingles(string $text, int $n): array
    {
        $out = [];
        foreach ($this->rawShingles($text, $n) as $s) {
            $out[$s] = true;
        }

        return $out;
    }

    /** @return string[] */
    private function rawShingles(string $text, int $n): array
    {
        $words = $this->words($text);
        if (count($words) < $n) {
            return [];
        }

        $shingles = [];
        for ($i = 0, $c = count($words) - $n; $i <= $c; ++$i) {
            $shingles[] = implode(' ', array_slice($words, $i, $n));
        }

        return $shingles;
    }

    /** @return string[] */
    private function words(string $text): array
    {
        return preg_split('/[^a-zа-яё0-9]+/ui', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Детектор near-duplicate контента (доктрина пакета _seo / SEO Guide 4.9):
 * scaled-content / doorway-страницы — главный риск для каталога из 438+ однотипных
 * карточек, генерируемых одной LLM-цепочкой. Google демоутит кластеры, где
 * «один профиль ×N с разными именами».
 *
 * Метрика — Jaccard по word-shingles (k-граммам слов). Пороги из пакета:
 *   body  ≥ 0.85 → DROP (near-duplicate, не публиковать)
 *         ≥ 0.60 → WARN (каннибализация — проверить/консолидировать)
 *   title ≥ 0.70 → дубликат заголовка
 *
 * Stateless и детерминированный — без LLM/сети. Для батча корпус шинглуется один
 * раз (shingles() публичный), сравнение идёт по предпосчитанным множествам.
 */
class NearDuplicateDetector
{
    private const SHINGLE_SIZE = 3; // word 3-граммы — баланс чувствительности/шума

    public const DROP_THRESHOLD  = 0.85; // near-duplicate → не публиковать
    public const WARN_THRESHOLD  = 0.60; // каннибализация → флаг
    public const TITLE_THRESHOLD = 0.70; // дубль заголовка

    /**
     * Нормализованные word-shingles текста (множество для Jaccard).
     * Короткий текст (< size слов) шинглуется по словам, иначе пересечение всегда пусто.
     *
     * @return array<string,true>
     */
    public function shingles(string $text, int $size = self::SHINGLE_SIZE): array
    {
        $text  = mb_strtolower(strip_tags($text));
        $text  = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n     = count($words);

        $set = [];
        if ($n < $size) {
            foreach ($words as $w) {
                $set[$w] = true;
            }
            return $set;
        }
        for ($i = 0; $i + $size <= $n; $i++) {
            $set[implode(' ', array_slice($words, $i, $size))] = true;
        }

        return $set;
    }

    /**
     * Jaccard двух shingle-множеств: |A∩B| / |A∪B|, 0..1.
     *
     * @param array<string,true> $a
     * @param array<string,true> $b
     */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));
        if ($inter === 0) {
            return 0.0;
        }
        $union = count($a) + count($b) - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }

    public function similarity(string $a, string $b, int $size = self::SHINGLE_SIZE): float
    {
        return $this->jaccard($this->shingles($a, $size), $this->shingles($b, $size));
    }

    /**
     * Ближайший элемент корпуса к тексту (по предпосчитанным shingle-множествам).
     *
     * @param array<string,true>                  $textShingles
     * @param array<array-key,array<string,true>>  $corpusShingles  id => shingle-set
     * @return array{score: float, id: array-key|null}
     */
    public function nearest(array $textShingles, array $corpusShingles): array
    {
        $best   = 0.0;
        $bestId = null;
        foreach ($corpusShingles as $id => $set) {
            $s = $this->jaccard($textShingles, $set);
            if ($s > $best) {
                $best   = $s;
                $bestId = $id;
            }
        }

        return ['score' => $best, 'id' => $bestId];
    }
}

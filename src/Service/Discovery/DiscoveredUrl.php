<?php

namespace App\Service\Discovery;

/**
 * Результат discovery-шага: один URL-кандидат с метаданными для очереди.
 *
 * - $sourceType: own_site|marketplace|social|article_review|mention (см. SourceTypeClassifier)
 * - $tier:       1 (own_site) | 2 (corpus) | 3 (mentions/social)
 * - $relevanceScore: 0..1, выше = увереннее, что это про нужный бренд
 * - $live:       прошёл ли HTTP-проверку (актуально только для own_site-кандидатов;
 *                для остальных всегда false — verifyUrl дорогой, его не зовём)
 */
final readonly class DiscoveredUrl
{
    public function __construct(
        public string $url,
        public string $sourceType,
        public int $tier,
        public float $relevanceScore,
        public bool $live = false,
    ) {
    }
}

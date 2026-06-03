<?php

namespace App\Service;

use App\Entity\Brand;

/**
 * Retrieval для генерации: по бренду достаёт релевантные чанки из Qdrant и
 * собирает «Проверенные факты» для промпта. Жёсткий gate качества — если
 * фактов мало или релевантность низкая, возвращает null → генерация уходит
 * в legacy-режим (модель пишет из своих знаний), а не заземляется на шуме.
 */
class BrandRagService
{
    private const TOP_K       = 6;
    private const MIN_CHUNKS  = 3;     // меньше — не заземляем
    private const MIN_SCORE   = 0.5;   // cosine; ниже — мусорная релевантность
    private const MAX_CONTEXT_CHARS = 6000;

    public function __construct(
        private readonly EmbeddingService   $embedder,
        private readonly VectorStoreService $vectors,
    ) {
    }

    /**
     * @return array{context:?string, score:?float, chunks:int}
     *         context=null → нет годного grounding (fallback на legacy)
     */
    public function retrieve(Brand $brand): array
    {
        $brandId = $brand->getId();
        if ($brandId === null) {
            return ['context' => null, 'score' => null, 'chunks' => 0];
        }

        $query = trim(sprintf('%s %s одежда бренд', (string) $brand->getTitle(), (string) $brand->getCity()));

        try {
            $qvec = $this->embedder->embed($query);
            $hits = $this->vectors->searchByBrand($brandId, $qvec, self::TOP_K);
        } catch (\Throwable) {
            return ['context' => null, 'score' => null, 'chunks' => 0];
        }

        $count = count($hits);
        $topScore = $count > 0 ? (float) ($hits[0]['score'] ?? 0) : null;

        if ($count < self::MIN_CHUNKS || $topScore === null || $topScore < self::MIN_SCORE) {
            return ['context' => null, 'score' => $topScore, 'chunks' => $count];
        }

        $context = $this->assemble($hits);

        return ['context' => $context, 'score' => $topScore, 'chunks' => $count];
    }

    /** @param array<int,array{score:float,payload:array}> $hits */
    private function assemble(array $hits): string
    {
        $blocks = [];
        $total = 0;
        foreach ($hits as $hit) {
            $text = trim((string) ($hit['payload']['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $src = (string) ($hit['payload']['source_url'] ?? '');
            $block = ($src !== '' ? "Источник: {$src}\n" : '') . $text;

            if ($total + mb_strlen($block) > self::MAX_CONTEXT_CHARS) {
                break;
            }
            $blocks[] = $block;
            $total += mb_strlen($block);
        }

        return implode("\n\n---\n\n", $blocks);
    }
}

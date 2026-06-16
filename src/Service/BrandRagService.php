<?php

namespace App\Service;

use App\Entity\Brand;

/**
 * Retrieval для генерации: по бренду достаёт релевантные чанки из Qdrant и
 * собирает «Проверенные факты» для промпта. Жёсткий gate качества — если
 * фактов мало или релевантность низкая, возвращает null → генерация уходит
 * в legacy-режим (модель пишет из своих знаний), а не заземляется на шуме.
 *
 * Адаптивно: у бренда мало чанков → один запрос (все чанки и так влезают,
 * лишние embed-вызовы не тратим). Много чанков (контентный сайт) → запросы
 * по аспектам (ассортимент/материалы/философия/...), чтобы покрыть разные
 * грани, а не топ-6 по одному общему запросу.
 */
class BrandRagService
{
    private const TOP_K            = 6;     // одиночный запрос
    private const MULTI_ASPECT_MIN = 8;     // больше этого числа чанков → multi-aspect
    private const PER_ASPECT       = 3;     // сколько брать на каждый аспект
    private const MAX_HITS         = 8;     // итоговый максимум чанков в контекст
    private const MIN_CHUNKS       = 3;     // меньше — не заземляем
    private const MIN_SCORE        = 0.5;   // cosine; ниже — мусорная релевантность

    /** Сигналы «это про одежду/торговлю» — хоть один в корпусе, иначе омоним (анти-Mauritius). */
    private const FASHION_SIGNALS = [
        'одежд', 'коллекц', 'бренд', 'магазин', 'купить', 'носить', 'ткан', 'размер', 'ассортимент',
        'мода', 'модн', 'стиль', 'пошив', 'фабрик', 'дизайнер', 'лукбук', 'футболк', 'платье', 'куртк',
        'джинс', 'обув', 'аксессуар', 'текстиль', 'худи', 'свитшот', 'кроссовк', 'сумк', 'трикотаж',
        'fashion', 'wear', 'clothing', 'apparel', 'shop', 'store', 'sale', 'sneaker',
    ];
    private const MAX_CONTEXT_CHARS = 6000;
    private const RELEVANCE_FLOOR  = 0.35;  // payload-relevance ниже (но >0) — омоним/мусор, выкидываем

    /** source_type, считающиеся собственным сайтом бренда (вкл. legacy official_site). */
    private const OWN_SITE_TYPES = ['own_site', 'official_site'];

    /** Грани бренда для multi-aspect запросов (дополняются названием). */
    private const ASPECTS = [
        'ассортимент и товары',
        'материалы ткани качество',
        'философия и история бренда',
        'целевая аудитория и стиль',
        'производство город доставка',
    ];

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
            return $this->miss();
        }

        try {
            $count = $this->vectors->countByBrand($brandId);
            if ($count === 0) {
                return $this->miss();
            }
            $hits = $count <= self::MULTI_ASPECT_MIN
                ? $this->singleQuery($brand, $brandId, $count)
                : $this->multiAspect($brand, $brandId);
        } catch (\Throwable) {
            return $this->miss();
        }

        $cnt = count($hits);
        $topScore = $cnt > 0 ? (float) ($hits[0]['score'] ?? 0) : null;

        if ($cnt < self::MIN_CHUNKS || $topScore === null || $topScore < self::MIN_SCORE) {
            return ['context' => null, 'score' => $topScore, 'chunks' => $cnt];
        }

        $context = $this->assemble($hits);

        // Гард топикальности (анти-омоним): cosine высок, потому что корпус «про {имя}», но имя —
        // омоним (страна Mauritius, браузер Vivaldi, страховая Wysh…). Если в собранном контексте
        // НЕТ ни одного fashion/commerce-сигнала — это почти наверняка чужая сущность, а не бренд
        // одежды. Не заземляем → grounded-only уведёт в deferred (не тратим gemma на чужой корпус,
        // и refusal-гейт остаётся последней сеткой). Один сигнал = достаточно (мало false-negative).
        if (!$this->looksLikeFashion($context)) {
            return ['context' => null, 'score' => $topScore, 'chunks' => $cnt];
        }

        return ['context' => $context, 'score' => $topScore, 'chunks' => $cnt];
    }

    /** Есть ли в тексте хоть один сигнал одежды/торговли (иначе корпус — про чужую сущность-омоним). */
    private function looksLikeFashion(string $text): bool
    {
        $t = mb_strtolower($text);
        foreach (self::FASHION_SIGNALS as $s) {
            if (mb_strpos($t, $s) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Один запрос — для брендов с малым числом чанков (возвращаются все). */
    private function singleQuery(Brand $brand, int $brandId, int $count): array
    {
        $query = trim(sprintf('%s %s одежда бренд', (string) $brand->getTitle(), (string) $brand->getCity()));
        $qvec  = $this->embedder->embed($query);

        return $this->vectors->searchByBrand($brandId, $qvec, min($count, self::TOP_K));
    }

    /** Запросы по аспектам (батч-embed за 1 вызов) + дедуп по id, лучший score. */
    private function multiAspect(Brand $brand, int $brandId): array
    {
        $title   = trim((string) $brand->getTitle());
        $queries = array_map(static fn(string $a) => trim($title . ' ' . $a), self::ASPECTS);
        $vectors = $this->embedder->embedBatch($queries);

        $byId = [];
        foreach ($vectors as $qvec) {
            foreach ($this->vectors->searchByBrand($brandId, $qvec, self::PER_ASPECT) as $hit) {
                $id = $hit['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $score = (float) ($hit['score'] ?? 0);
                if (!isset($byId[$id]) || $score > (float) $byId[$id]['score']) {
                    $hit['score'] = $score;
                    $byId[$id] = $hit;
                }
            }
        }

        $hits = array_values($byId);
        usort($hits, static fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($hits, 0, self::MAX_HITS);
    }

    /** @return array{context:null,score:null,chunks:0} */
    private function miss(): array
    {
        return ['context' => null, 'score' => null, 'chunks' => 0];
    }

    /**
     * Carry-forward веса источника: выкидываем явно низко-релевантные чанки
     * (омоним/мусор, relevance>0 и <floor; 0/отсутствие = не размечено → оставляем)
     * и ставим own_site-чанки раньше, сохраняя cosine-порядок внутри групп
     * (usort в PHP 8.2 стабилен). Гейт в retrieve() при этом не трогаем.
     *
     * @param array<int,array{score:float,payload:array}> $hits
     * @return array<int,array{score:float,payload:array}>
     */
    private function prioritize(array $hits): array
    {
        $kept = [];
        foreach ($hits as $hit) {
            $rel = $hit['payload']['relevance'] ?? null;
            if ($rel !== null && (float) $rel > 0 && (float) $rel < self::RELEVANCE_FLOOR) {
                continue;
            }
            $kept[] = $hit;
        }

        // Если фильтр выкосил всё (например, всё было размечено низко), не остаёмся
        // с пустым контекстом — откатываемся к исходному набору, гейт уже пройден.
        if ($kept === []) {
            $kept = $hits;
        }

        usort(
            $kept,
            fn($a, $b) => $this->isOwnSite($b) <=> $this->isOwnSite($a),
        );

        return $kept;
    }

    private function isOwnSite(array $hit): int
    {
        $type = (string) ($hit['payload']['source_type'] ?? '');

        return in_array($type, self::OWN_SITE_TYPES, true) ? 1 : 0;
    }

    /** @param array<int,array{score:float,payload:array}> $hits */
    private function assemble(array $hits): string
    {
        $hits = $this->prioritize($hits);

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

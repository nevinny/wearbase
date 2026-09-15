<?php

namespace App\Service\Keyword;

use App\Entity\Brand;

/**
 * Сбор SEO-ключевиков для бренда от провайдера (Wordstat). LIVE-вызов —
 * выполняется ЗАРАНЕЕ командой app:brand:keywords и кэшируется в
 * BrandRagPipeline.keywords; генерация читает уже готовое (без live-вызова,
 * чтобы не упираться в квоту Wordstat при параллельных прогонах).
 */
class KeywordService
{
    public function __construct(
        private readonly KeywordProviderInterface $provider,
        private readonly KeywordBlocklist $blocklist,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /** Fashion-якоря: фраза релевантна, если содержит имя бренда ИЛИ один из них. */
    private const FASHION_TERMS = [
        'одежд', 'бренд', 'куртк', 'футболк', 'худи', 'свитшот', 'толстовк', 'штаны',
        'джинс', 'платье', 'кофт', 'рубашк', 'пальто', 'магазин', 'купить', 'носить',
        'обувь', 'аксессуар', 'мерч', 'streetwear', 'стритвир', 'лукбук', 'коллекци',
    ];

    /**
     * Live-сбор ключевиков бренда от провайдера (для app:brand:keywords).
     * Фильтрует мусор брендов-омонимов: оставляет фразы с именем бренда или
     * fashion-термином (иначе Wordstat-associations тянут нерелевантное:
     * SYNOPTIC→«синупрет», synthetics→«синтетика»).
     *
     * @return array<int,array{keyword:string,type:string,monthlyShows:?int}>
     */
    public function collect(Brand $brand): array
    {
        if (!$this->provider->isConfigured()) {
            return [];
        }

        $seed = trim((string) $brand->getTitle());
        if ($seed === '') {
            return [];
        }

        $brandNeedle = mb_strtolower(str_replace([' ', '-', '.'], '', $seed));

        return array_values(array_filter(
            $this->provider->keywordsFor($seed),
            fn(array $row) => $this->relevant((string) ($row['keyword'] ?? ''), $brandNeedle),
        ));
    }

    private function relevant(string $keyword, string $brandNeedle): bool
    {
        // Fail-closed: минус-слова раньше релевантности. Порядок важен — «murka
        // onlyfans» содержит имя бренда, поэтому проверку релевантности проходит.
        if ($this->blocklist->isBlocked($keyword)) {
            return false;
        }

        $kw = mb_strtolower($keyword);
        if ($brandNeedle !== '' && mb_strlen($brandNeedle) >= 3
            && str_contains(str_replace([' ', '-', '.'], '', $kw), $brandNeedle)) {
            return true;
        }
        foreach (self::FASHION_TERMS as $term) {
            if (str_contains($kw, $term)) {
                return true;
            }
        }

        return false;
    }
}

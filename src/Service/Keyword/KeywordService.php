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
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * Live-сбор ключевиков бренда от провайдера (для app:brand:keywords).
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

        return $this->provider->keywordsFor($seed);
    }
}

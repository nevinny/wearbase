<?php

namespace App\Service\Keyword;

use App\Entity\Brand;

/**
 * Возвращает SEO-ключевики для бренда. Если Wordstat-провайдер сконфигурирован
 * и отдал фразы — используем их (реальные частотные запросы). Иначе null —
 * вызывающий оставляет LLM-сгенерированные ключевики (текущее поведение).
 */
class KeywordService
{
    public function __construct(
        private readonly KeywordProviderInterface $provider,
    ) {
    }

    /** @return string|null comma-joined ключевики, либо null (оставить LLM-вариант) */
    public function deriveKeywords(Brand $brand): ?string
    {
        if (!$this->provider->isConfigured()) {
            return null;
        }

        $seed = trim((string) $brand->getTitle());
        if ($seed === '') {
            return null;
        }

        $phrases = $this->provider->keywordsFor($seed);
        if ($phrases === []) {
            return null;
        }

        return mb_substr(implode(', ', $phrases), 0, 200);
    }
}

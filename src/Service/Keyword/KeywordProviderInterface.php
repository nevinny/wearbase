<?php

namespace App\Service\Keyword;

/**
 * Источник ключевых фраз по сид-запросу (бренду). Реализации: Wordstat (реальные
 * частотности) и т.п. Если провайдер не сконфигурирован/упал — возвращает [],
 * и вызывающий код откатывается на LLM-ключевики.
 */
interface KeywordProviderInterface
{
    public function isConfigured(): bool;

    /**
     * @return array<int,array{keyword:string,type:string,monthlyShows:?int}>
     *         type: 'origin' (фраза включает запрос) | 'related' (похожие запросы)
     */
    public function keywordsFor(string $seed, int $limit = 30): array;
}

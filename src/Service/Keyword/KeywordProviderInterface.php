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
     * @return string[] ключевые фразы по убыванию частотности (без частот)
     */
    public function keywordsFor(string $seed, int $limit = 8): array;
}

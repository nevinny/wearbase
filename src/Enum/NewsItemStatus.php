<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Жизненный цикл news_item:
 * discovered → fetched → rewritten → ready → published | rejected.
 */
enum NewsItemStatus: string
{
    case Discovered = 'discovered'; // найдено в фиде
    case Fetched    = 'fetched';    // текст статьи догружен
    case Rewritten  = 'rewritten';  // LLM-рерайт готов (до гейтов)
    case Ready      = 'ready';      // прошло шингл-гейт, ждёт модерации/автопубликации
    case Published  = 'published';
    case Rejected   = 'rejected';   // шингл-гейт или ручной reject

    /** Статусы, которые берёт в работу app:news:process. */
    public static function processable(): array
    {
        return [self::Discovered, self::Fetched];
    }
}

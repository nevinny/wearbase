<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Enum\TosMode;

/**
 * Каталог MVP-источников (единая правда для миграции и app:news:sources:seed).
 * Фиды проверены чтением 2026-08-25 (_docs/news-sources.md §1),
 * правовые режимы — _docs/news-sources-tos.md.
 */
final class NewsSourcesCatalog
{
    /**
     * @return array<int, array{name: string, feedUrl: string, tosMode: TosMode, active: bool, rubricHint: ?string}>
     */
    public static function all(): array
    {
        return [
            // ── MVP: 4 факто-only источника ────────────────────────────────
            ['name' => 'Parents.ru', 'feedUrl' => 'https://www.parents.ru/rss-feeds/rss.xml', 'tosMode' => TosMode::FactsOnly, 'active' => true, 'rubricHint' => 'дети'],
            ['name' => 'Woman.ru', 'feedUrl' => 'https://www.woman.ru/rss/', 'tosMode' => TosMode::FactsOnly, 'active' => true, 'rubricHint' => 'мода'],
            ['name' => 'The-Day', 'feedUrl' => 'https://the-day.ru/rss-feeds/rss.xml', 'tosMode' => TosMode::FactsOnly, 'active' => true, 'rubricHint' => null],
            ['name' => 'Sobaka.ru', 'feedUrl' => 'https://www.sobaka.ru/rss/news.xml', 'tosMode' => TosMode::FactsOnly, 'active' => true, 'rubricHint' => null],
            // ── Запрещённые правообладателем: в конвейере не участвуют ────
            ['name' => 'Buro 24/7', 'feedUrl' => 'https://www.buro247.ru/xml/rss.xml', 'tosMode' => TosMode::Forbidden, 'active' => false, 'rubricHint' => null],
            ['name' => 'РБК Стиль', 'feedUrl' => 'https://style.rbc.ru/', 'tosMode' => TosMode::Forbidden, 'active' => false, 'rubricHint' => null],
        ];
    }
}

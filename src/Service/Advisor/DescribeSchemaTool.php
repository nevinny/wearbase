<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use Doctrine\DBAL\Connection;

/**
 * Читает схему таблиц БД из information_schema и возвращает человекопонятное описание
 * для LLM-агента. Кэширует результат на время запроса (схема не меняется между деплоями).
 *
 * Показывает только таблицы, релевантные для аналитики: GSC, Яндекс, бренды, ключевики,
 * снапшоты, идеи. Полный список таблиц — в конструкторе.
 */
final class DescribeSchemaTool
{
    private const RELEVANT_TABLES = [
        'gsc_page_stats',
        'gsc_index_status',
        'yandex_history',
        'yandex_index_status',
        'yandex_query_stats',
        'brand',
        'brand_keyword',
        'brand_outbound_click',
        'state_snapshot',
        'advisor_idea',
        'advisor_experiment',
        'advisor_run',
        'drip_health',
    ];

    private const TABLE_COMMENTS = [
        'gsc_page_stats'    => 'Google Search Console: показы/клики/позиция по дням на URL',
        'gsc_index_status'  => 'Google Search Console: статус индексации по URL бренда',
        'yandex_history'    => 'Яндекс.Вебмастер: дневные суммы показов/кликов/страниц в поиске',
        'yandex_index_status' => 'Яндекс.Вебмастер: статус страниц брендов в поиске Яндекса',
        'yandex_query_stats' => 'Яндекс.Вебмастер: топ-500 поисковых запросов (показы/клики/позиция)',
        'brand'             => 'Бренды: статус, публикация, контакты, ниша, происхождение',
        'brand_keyword'     => 'Ключевые слова брендов с monthly_shows',
        'brand_outbound_click' => 'Клики по внешним ссылкам брендов (исходящий трафик)',
        'state_snapshot'    => 'Снимки KPI-вектора проекта (метрики + дельта JSON)',
        'advisor_idea'      => 'Бэклог идей советника: заголовок, гипотеза, ICE, статус',
        'advisor_experiment' => 'Эксперименты советника: ветка → деплой → замер → вердикт',
        'advisor_run'       => 'Аудит тиков советника: дайджест, решения',
        'drip_health'       => 'Множитель темпа дрип-публикации по здоровью индекса Яндекса',
    ];

    private const EXTRA_HINTS = [
        'gsc_page_stats' => 'query IS NULL = агрегат по page_url; query IS NOT NULL = по фразе',
        'yandex_history' => 'pages_in_search заполняется не каждый день (через день)',
        'yandex_query_stats' => 'date_from/date_to — скользящее окно, последний date_to = свежие данные',
        'brand' => 'status: new(не опубликован), active(опубликован), disabled, deleted; published_at = дата дрип-публикации',
    ];

    private ?string $cache = null;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Возвращает описание схемы БД для LLM: таблицы, колонки, типы, связи, хинты.
     */
    public function describe(): string
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $parts = [];
        $parts[] = 'База данных WEARBASE (MySQL). Схема релевантных таблиц:';
        $parts[] = '';

        foreach (self::RELEVANT_TABLES as $table) {
            $comment = self::TABLE_COMMENTS[$table] ?? '';
            $parts[] = sprintf('── %s ──%s', $table, $comment !== '' ? ' ' . $comment : '');
            $parts[] = '';

            $columns = $this->fetchColumns($table);
            if ($columns === []) {
                $parts[] = '  (таблица не найдена или пуста)';
                $parts[] = '';
                continue;
            }

            foreach ($columns as $col) {
                $parts[] = sprintf('  %s %s%s%s',
                    $col['name'],
                    $col['type'],
                    $col['nullable'] ? ' NULL' : ' NOT NULL',
                    $col['default'] !== null ? " default={$col['default']}" : '',
                );
            }

            $hint = self::EXTRA_HINTS[$table] ?? null;
            if ($hint !== null) {
                $parts[] = '  → ' . $hint;
            }

            $parts[] = '';
        }

        $this->cache = implode("\n", $parts);

        return $this->cache;
    }

    /**
     * @return list<array{name:string,type:string,nullable:bool,default:string|null}>
     */
    private function fetchColumns(string $table): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, ?) AS COLUMN_DEFAULT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 ORDER BY ORDINAL_POSITION',
                ['', $table],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'     => (string) $r['COLUMN_NAME'],
                'type'     => (string) $r['COLUMN_TYPE'],
                'nullable' => ((string) $r['IS_NULLABLE']) === 'YES',
                'default'  => $r['COLUMN_DEFAULT'] !== null ? (string) $r['COLUMN_DEFAULT'] : null,
            ];
        }

        return $out;
    }
}
